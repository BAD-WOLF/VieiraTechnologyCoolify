data "aws_ami" "ubuntu" {
  count = var.ami_id == "" ? 1 : 0

  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

locals {
  ami_id       = var.ami_id != "" ? var.ami_id : data.aws_ami.ubuntu[0].id
  node_subnets = var.public_database_access ? aws_subnet.public[*].id : aws_subnet.private[*].id
}

resource "aws_key_pair" "operator" {
  key_name   = "${local.name}-operator"
  public_key = var.ssh_public_key

  tags = local.tags
}

data "aws_iam_policy_document" "ec2_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "instance" {
  name               = "${local.name}-instance"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json

  tags = local.tags
}

# The hosts can only write to the regional backup bucket. Containers can not use this role:
# the metadata service requires IMDSv2 with a hop limit of 1 and is also blocked by the firewall.
data "aws_iam_policy_document" "backups" {
  statement {
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.backups.arn]
  }

  statement {
    actions   = ["s3:PutObject", "s3:GetObject", "s3:AbortMultipartUpload"]
    resources = ["${aws_s3_bucket.backups.arn}/*"]
  }
}

resource "aws_iam_role_policy" "backups" {
  name   = "backups"
  role   = aws_iam_role.instance.id
  policy = data.aws_iam_policy_document.backups.json
}

resource "aws_iam_instance_profile" "instance" {
  name = "${local.name}-instance"
  role = aws_iam_role.instance.name

  tags = local.tags
}

resource "aws_instance" "control" {
  ami                    = local.ami_id
  instance_type          = var.control_instance_type
  subnet_id              = aws_subnet.public[0].id
  vpc_security_group_ids = [aws_security_group.control.id]
  key_name               = aws_key_pair.operator.key_name
  iam_instance_profile   = aws_iam_instance_profile.instance.name

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  root_block_device {
    volume_size = 50
    volume_type = "gp3"
    encrypted   = true
  }

  tags = merge(local.tags, { Name = "${local.name}-control", Role = "control" })

  lifecycle {
    ignore_changes = [ami]
  }
}

resource "aws_eip" "control" {
  domain   = "vpc"
  instance = aws_instance.control.id

  tags = merge(local.tags, { Name = "${local.name}-control" })
}

resource "aws_ebs_volume" "control_data" {
  availability_zone = aws_instance.control.availability_zone
  size              = var.control_data_volume_gb
  type              = "gp3"
  encrypted         = true

  tags = merge(local.tags, { Name = "${local.name}-control-data", Snapshot = local.snapshot_tag })
}

resource "aws_volume_attachment" "control_data" {
  device_name = "/dev/sdf"
  volume_id   = aws_ebs_volume.control_data.id
  instance_id = aws_instance.control.id
}

resource "aws_instance" "node" {
  count = var.node_count

  ami                         = local.ami_id
  instance_type               = var.node_instance_type
  subnet_id                   = local.node_subnets[count.index % length(local.node_subnets)]
  vpc_security_group_ids      = [aws_security_group.node.id]
  key_name                    = aws_key_pair.operator.key_name
  iam_instance_profile        = aws_iam_instance_profile.instance.name
  associate_public_ip_address = var.public_database_access

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  root_block_device {
    volume_size = var.node_root_volume_gb
    volume_type = "gp3"
    encrypted   = true
  }

  tags = merge(local.tags, { Name = format("%s-node-%02d", local.name, count.index + 1), Role = "node" })

  lifecycle {
    ignore_changes = [ami]
  }
}

resource "aws_ebs_volume" "node_data" {
  count = var.node_count

  availability_zone = aws_instance.node[count.index].availability_zone
  size              = var.node_data_volume_gb
  type              = "gp3"
  iops              = var.node_data_volume_iops
  throughput        = var.node_data_volume_throughput
  encrypted         = true

  tags = merge(local.tags, { Name = format("%s-node-%02d-data", local.name, count.index + 1), Snapshot = local.snapshot_tag })
}

resource "aws_volume_attachment" "node_data" {
  count = var.node_count

  device_name = "/dev/sdf"
  volume_id   = aws_ebs_volume.node_data[count.index].id
  instance_id = aws_instance.node[count.index].id
}

resource "aws_eip" "node" {
  count = var.public_database_access ? var.node_count : 0

  domain   = "vpc"
  instance = aws_instance.node[count.index].id

  tags = merge(local.tags, { Name = format("%s-node-%02d", local.name, count.index + 1) })
}
