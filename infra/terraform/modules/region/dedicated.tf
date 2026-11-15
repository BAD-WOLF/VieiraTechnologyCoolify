# One VM per Enterprise tenant (wiki 3, "isolamento por VM"): same network, security groups and
# edge as the shared nodes, but registered in Coolify as dedicated to a single team.
resource "aws_instance" "dedicated" {
  for_each = var.dedicated_nodes

  ami                         = local.ami_id
  instance_type               = coalesce(each.value.instance_type, var.node_instance_type)
  subnet_id                   = local.node_subnets[index(keys(var.dedicated_nodes), each.key) % length(local.node_subnets)]
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

  tags = merge(local.tags, {
    Name          = "${local.name}-dedicated-${each.key}"
    Role          = "node"
    DedicatedTeam = tostring(each.value.team_id)
  })

  lifecycle {
    ignore_changes = [ami]
  }
}

resource "aws_ebs_volume" "dedicated_data" {
  for_each = var.dedicated_nodes

  availability_zone = aws_instance.dedicated[each.key].availability_zone
  size              = coalesce(each.value.data_volume_gb, var.node_data_volume_gb)
  type              = "gp3"
  iops              = var.node_data_volume_iops
  throughput        = var.node_data_volume_throughput
  encrypted         = true

  tags = merge(local.tags, { Name = "${local.name}-dedicated-${each.key}-data", Snapshot = local.snapshot_tag })
}

resource "aws_volume_attachment" "dedicated_data" {
  for_each = var.dedicated_nodes

  device_name = "/dev/sdf"
  volume_id   = aws_ebs_volume.dedicated_data[each.key].id
  instance_id = aws_instance.dedicated[each.key].id
}

resource "aws_eip" "dedicated" {
  for_each = var.public_database_access ? var.dedicated_nodes : {}

  domain   = "vpc"
  instance = aws_instance.dedicated[each.key].id

  tags = merge(local.tags, { Name = "${local.name}-dedicated-${each.key}" })
}

resource "aws_lb_target_group_attachment" "dedicated" {
  for_each = {
    for pair in setproduct(keys(local.edge_target_groups), keys(var.dedicated_nodes)) :
    "${pair[0]}-${pair[1]}" => { group = pair[0], node = pair[1] }
  }

  target_group_arn = aws_lb_target_group.edge[each.value.group].arn
  target_id        = aws_instance.dedicated[each.value.node].id
  port             = local.edge_target_groups[each.value.group].port
}
