# Active/active control plane (wiki 6): N instances in two zones behind an Application Load
# Balancer, all sharing PostgreSQL (RDS Multi-AZ) and Redis (ElastiCache with failover). Sessions
# live in the database, the realtime server uses the Redis adapter and every scheduled task runs
# on one instance at a time (PLATFORM_CONTROL_PLANE_CLUSTER=true), so any instance or zone can fail.
locals {
  panel_ports = {
    app      = { port = 8000, health = "/api/health" }
    realtime = { port = 6001, health = "/" }
    terminal = { port = 6002, health = "/" }
  }
  certificate_arn = var.certificate_arn != "" ? var.certificate_arn : try(aws_acm_certificate_validation.panel[0].certificate_arn, "")
  env_file = join("\n", concat([
    "APP_ID=${random_id.app_id.hex}",
    "APP_NAME=Hostrema",
    "APP_ENV=production",
    "APP_KEY=${var.app_key != "" ? var.app_key : "base64:${random_bytes.app_key.base64}"}",
    "APP_LOCALE=pt-br",
    "DB_HOST=${aws_db_instance.coolify.address}",
    "DB_PORT=${aws_db_instance.coolify.port}",
    "DB_DATABASE=coolify",
    "DB_USERNAME=coolify",
    "DB_PASSWORD=${random_password.db.result}",
    "REDIS_HOST=${aws_elasticache_replication_group.redis.primary_endpoint_address}",
    "REDIS_PORT=6379",
    "REDIS_PASSWORD=",
    "PUSHER_APP_ID=${random_id.pusher_id.hex}",
    "PUSHER_APP_KEY=${random_password.pusher_key.result}",
    "PUSHER_APP_SECRET=${random_password.pusher_secret.result}",
    "PLATFORM_ENABLED=true",
    "PLATFORM_CONTROL_PLANE_CLUSTER=true",
    "AUTOUPDATE=false",
  ], length(var.app_previous_keys) > 0 ? ["APP_PREVIOUS_KEYS=${join(",", var.app_previous_keys)}"] : [], [for key, value in var.extra_env : "${key}=${value}"]))
}

resource "random_id" "app_id" {
  byte_length = 16
}

resource "random_bytes" "app_key" {
  length = 32
}

resource "random_id" "pusher_id" {
  byte_length = 8
}

resource "random_password" "pusher_key" {
  length  = 32
  special = false
}

resource "random_password" "pusher_secret" {
  length  = 32
  special = false
}

resource "aws_ssm_parameter" "env" {
  count = local.cluster ? 1 : 0

  name  = "/hostrema/control-plane/env"
  type  = "SecureString"
  value = local.env_file
}

# Image of the cluster. Terraform only sets the first value: from then on the release workflow
# (upgrade-control-plane.sh) records each version it deploys, so an instance created later starts
# with the same version as the others.
resource "aws_ssm_parameter" "image" {
  count = local.cluster ? 1 : 0

  name  = "/hostrema/control-plane/image"
  type  = "String"
  value = var.image

  lifecycle {
    ignore_changes = [value]
  }
}

resource "aws_ssm_parameter" "registry_password" {
  count = local.cluster && var.registry_username != "" ? 1 : 0

  name  = "/hostrema/control-plane/registry-password"
  type  = "SecureString"
  value = var.registry_password
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

data "aws_iam_policy_document" "read_config" {
  statement {
    actions   = ["ssm:GetParameter"]
    resources = ["arn:aws:ssm:${var.region}:*:parameter/hostrema/control-plane/*"]
  }

  statement {
    actions   = ["ssm:PutParameter"]
    resources = ["arn:aws:ssm:${var.region}:*:parameter/hostrema/control-plane/image"]
  }

  statement {
    actions   = ["kms:Decrypt"]
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "kms:ViaService"
      values   = ["ssm.${var.region}.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "cluster" {
  count = local.cluster ? 1 : 0

  name               = "${local.name}-cluster"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json
}

resource "aws_iam_role_policy" "cluster" {
  count = local.cluster ? 1 : 0

  name   = "config"
  role   = aws_iam_role.cluster[0].id
  policy = data.aws_iam_policy_document.read_config.json
}

resource "aws_iam_instance_profile" "cluster" {
  count = local.cluster ? 1 : 0

  name = "${local.name}-cluster"
  role = aws_iam_role.cluster[0].name
}

resource "aws_security_group" "alb" {
  count = local.cluster ? 1 : 0

  name        = "${local.name}-alb"
  description = "Dashboard load balancer"
  vpc_id      = aws_vpc.this.id
}

resource "aws_vpc_security_group_ingress_rule" "alb" {
  for_each = local.cluster ? { http = 80, https = 443 } : {}

  security_group_id = aws_security_group.alb[0].id
  description       = "Dashboard (${each.key})"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = each.value
  to_port           = each.value
}

resource "aws_vpc_security_group_egress_rule" "alb" {
  count = local.cluster ? 1 : 0

  security_group_id            = aws_security_group.alb[0].id
  description                  = "Instances of the control plane"
  referenced_security_group_id = aws_security_group.app.id
  ip_protocol                  = "-1"
}

resource "aws_vpc_security_group_ingress_rule" "app_from_alb" {
  for_each = local.cluster ? local.panel_ports : {}

  security_group_id            = aws_security_group.app.id
  description                  = "${each.key} from the load balancer"
  referenced_security_group_id = aws_security_group.alb[0].id
  ip_protocol                  = "tcp"
  from_port                    = each.value.port
  to_port                      = each.value.port
}

resource "aws_instance" "cluster" {
  count = local.cluster ? var.instance_count : 0

  ami                         = data.aws_ami.ubuntu.id
  instance_type               = var.instance_type
  subnet_id                   = aws_subnet.public[count.index % 2].id
  vpc_security_group_ids      = [aws_security_group.app.id]
  key_name                    = aws_key_pair.operator.key_name
  iam_instance_profile        = aws_iam_instance_profile.cluster[0].name
  associate_public_ip_address = true

  user_data = templatefile("${path.module}/templates/bootstrap.sh.tftpl", {
    name              = format("%s-%02d", local.name, count.index + 1)
    role              = var.worker_count > 0 ? "web" : "all"
    wait_for_panel    = count.index > 0
    region            = var.region
    env_parameter     = aws_ssm_parameter.env[0].name
    image             = var.image
    image_parameter   = aws_ssm_parameter.image[0].name
    redis_host        = aws_elasticache_replication_group.redis.primary_endpoint_address
    registry_username = var.registry_username
    registry_password = var.registry_username != "" ? aws_ssm_parameter.registry_password[0].name : ""
    health_url        = "https://${var.domain}/api/health"
  })

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  root_block_device {
    volume_size = var.disk_gb
    volume_type = "gp3"
    encrypted   = true
  }

  tags = { Name = format("%s-%02d", local.name, count.index + 1) }

  lifecycle {
    ignore_changes = [ami, user_data]
  }
}

resource "aws_cloudwatch_metric_alarm" "cluster_recover" {
  count = local.cluster ? var.instance_count : 0

  alarm_name          = format("%s-%02d-recover", local.name, count.index + 1)
  namespace           = "AWS/EC2"
  metric_name         = "StatusCheckFailed_System"
  statistic           = "Maximum"
  period              = 60
  evaluation_periods  = 2
  threshold           = 0
  comparison_operator = "GreaterThanThreshold"
  dimensions          = { InstanceId = aws_instance.cluster[count.index].id }
  alarm_actions       = ["arn:aws:automate:${var.region}:ec2:recover"]
}

# Queue workers: Horizon and the scheduler, outside the load balancer, so long deployments and
# server checks never compete with the dashboard for CPU (wiki 9, "Arquitetura monolítica").
resource "aws_instance" "worker" {
  count = local.cluster ? var.worker_count : 0

  ami                         = data.aws_ami.ubuntu.id
  instance_type               = var.worker_instance_type != "" ? var.worker_instance_type : var.instance_type
  subnet_id                   = aws_subnet.public[(count.index + 1) % 2].id
  vpc_security_group_ids      = [aws_security_group.app.id]
  key_name                    = aws_key_pair.operator.key_name
  iam_instance_profile        = aws_iam_instance_profile.cluster[0].name
  associate_public_ip_address = true

  user_data = templatefile("${path.module}/templates/bootstrap.sh.tftpl", {
    name              = format("%s-worker-%02d", local.name, count.index + 1)
    role              = "worker"
    wait_for_panel    = true
    region            = var.region
    env_parameter     = aws_ssm_parameter.env[0].name
    image             = var.image
    image_parameter   = aws_ssm_parameter.image[0].name
    redis_host        = aws_elasticache_replication_group.redis.primary_endpoint_address
    registry_username = var.registry_username
    registry_password = var.registry_username != "" ? aws_ssm_parameter.registry_password[0].name : ""
    health_url        = "https://${var.domain}/api/health"
  })

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  root_block_device {
    volume_size = var.disk_gb
    volume_type = "gp3"
    encrypted   = true
  }

  tags = { Name = format("%s-worker-%02d", local.name, count.index + 1) }

  lifecycle {
    ignore_changes = [ami, user_data]
  }
}

resource "aws_cloudwatch_metric_alarm" "worker_recover" {
  count = local.cluster ? var.worker_count : 0

  alarm_name          = format("%s-worker-%02d-recover", local.name, count.index + 1)
  namespace           = "AWS/EC2"
  metric_name         = "StatusCheckFailed_System"
  statistic           = "Maximum"
  period              = 60
  evaluation_periods  = 2
  threshold           = 0
  comparison_operator = "GreaterThanThreshold"
  dimensions          = { InstanceId = aws_instance.worker[count.index].id }
  alarm_actions       = ["arn:aws:automate:${var.region}:ec2:recover"]
}

resource "aws_lb" "panel" {
  count = local.cluster ? 1 : 0

  name               = "hostrema-panel"
  load_balancer_type = "application"
  subnets            = aws_subnet.public[*].id
  security_groups    = [aws_security_group.alb[0].id]
  idle_timeout       = 3600
}

resource "aws_lb_target_group" "panel" {
  for_each = local.cluster ? local.panel_ports : {}

  name     = "hostrema-${each.key}"
  port     = each.value.port
  protocol = "HTTP"
  vpc_id   = aws_vpc.this.id

  health_check {
    path    = each.value.health
    matcher = "200-499"
  }

  # Livewire uploads are kept on the instance that received them.
  stickiness {
    type    = "lb_cookie"
    enabled = each.key == "app"
  }
}

resource "aws_lb_target_group_attachment" "panel" {
  for_each = {
    for pair in setproduct(keys(local.cluster ? local.panel_ports : {}), range(local.cluster ? var.instance_count : 0)) :
    "${pair[0]}-${pair[1]}" => { group = pair[0], instance = pair[1] }
  }

  target_group_arn = aws_lb_target_group.panel[each.value.group].arn
  target_id        = aws_instance.cluster[each.value.instance].id
  port             = local.panel_ports[each.value.group].port
}

resource "aws_acm_certificate" "panel" {
  count = local.cluster && var.certificate_arn == "" ? 1 : 0

  domain_name       = var.domain
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_route53_record" "panel_validation" {
  for_each = {
    for option in flatten(aws_acm_certificate.panel[*].domain_validation_options) : option.domain_name => option
    if var.route53_zone_id != ""
  }

  zone_id = var.route53_zone_id
  name    = each.value.resource_record_name
  type    = each.value.resource_record_type
  ttl     = 300
  records = [each.value.resource_record_value]
}

resource "aws_acm_certificate_validation" "panel" {
  count = local.cluster && var.certificate_arn == "" && var.route53_zone_id != "" ? 1 : 0

  certificate_arn         = aws_acm_certificate.panel[0].arn
  validation_record_fqdns = [for record in aws_route53_record.panel_validation : record.fqdn]
}

resource "aws_lb_listener" "http" {
  count = local.cluster ? 1 : 0

  load_balancer_arn = aws_lb.panel[0].arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"

    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

resource "aws_lb_listener" "https" {
  count = local.cluster ? 1 : 0

  load_balancer_arn = aws_lb.panel[0].arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = local.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.panel["app"].arn
  }

  lifecycle {
    precondition {
      condition     = var.domain != "" && (var.certificate_arn != "" || var.route53_zone_id != "")
      error_message = "The cluster needs domain and either certificate_arn or route53_zone_id (to validate the certificate)."
    }
  }
}

resource "aws_lb_listener_rule" "realtime" {
  for_each = local.cluster ? {
    realtime = { priority = 10, paths = ["/app/*", "/apps/*"] }
    terminal = { priority = 20, paths = ["/terminal/ws*"] }
  } : {}

  listener_arn = aws_lb_listener.https[0].arn
  priority     = each.value.priority

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.panel[each.key].arn
  }

  condition {
    path_pattern {
      values = each.value.paths
    }
  }
}

resource "aws_route53_record" "panel_cluster" {
  count = local.cluster && var.route53_zone_id != "" && var.domain != "" ? 1 : 0

  zone_id = var.route53_zone_id
  name    = var.domain
  type    = "A"

  alias {
    name                   = aws_lb.panel[0].dns_name
    zone_id                = aws_lb.panel[0].zone_id
    evaluate_target_health = true
  }
}
