# Regional load balancer (wiki 2): TCP only, so TLS stays on the Coolify proxy of each node.
# It targets the edge router of every node, which forwards each host to the node running it.
locals {
  edge_target_groups = {
    http  = { listener = 80, port = var.edge_http_port }
    https = { listener = 443, port = var.edge_https_port }
  }
}

resource "aws_lb" "edge" {
  name                             = substr(replace("${local.name}-edge", "_", "-"), 0, 32)
  load_balancer_type               = "network"
  internal                         = false
  subnets                          = aws_subnet.public[*].id
  enable_cross_zone_load_balancing = true

  tags = local.tags
}

resource "aws_lb_target_group" "edge" {
  for_each = local.edge_target_groups

  name                   = substr(replace("${local.name}-${each.key}", "_", "-"), 0, 32)
  port                   = each.value.port
  protocol               = "TCP"
  target_type            = "instance"
  vpc_id                 = aws_vpc.this.id
  connection_termination = true
  deregistration_delay   = 30

  health_check {
    protocol            = "HTTP"
    port                = tostring(var.edge_health_port)
    path                = "/ping"
    interval            = 10
    healthy_threshold   = 3
    unhealthy_threshold = 3
  }

  tags = local.tags
}

resource "aws_lb_listener" "edge" {
  for_each = local.edge_target_groups

  load_balancer_arn = aws_lb.edge.arn
  port              = each.value.listener
  protocol          = "TCP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.edge[each.key].arn
  }

  tags = local.tags
}

resource "aws_lb_target_group_attachment" "edge" {
  for_each = {
    for pair in setproduct(keys(local.edge_target_groups), range(var.node_count)) :
    "${pair[0]}-${pair[1]}" => { group = pair[0], node = pair[1] }
  }

  target_group_arn = aws_lb_target_group.edge[each.value.group].arn
  target_id        = aws_instance.node[each.value.node].id
  port             = local.edge_target_groups[each.value.group].port
}
