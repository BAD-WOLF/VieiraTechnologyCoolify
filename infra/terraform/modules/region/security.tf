# Control node: bastion for Ansible, WireGuard endpoint of the Coolify control plane,
# regional monitoring (Prometheus, Loki, Grafana) and registry mirror.
resource "aws_security_group" "control" {
  name        = "${local.name}-control"
  description = "Region control node"
  vpc_id      = aws_vpc.this.id

  tags = merge(local.tags, { Name = "${local.name}-control" })
}

# Execution nodes: tenant workloads behind the regional load balancer.
resource "aws_security_group" "node" {
  name        = "${local.name}-node"
  description = "Region execution nodes"
  vpc_id      = aws_vpc.this.id

  tags = merge(local.tags, { Name = "${local.name}-node" })
}

resource "aws_vpc_security_group_ingress_rule" "control_ssh" {
  for_each = toset(var.admin_cidrs)

  security_group_id = aws_security_group.control.id
  description       = "SSH (bastion) from administrators"
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 22
  to_port           = 22
}

resource "aws_vpc_security_group_ingress_rule" "control_wireguard" {
  for_each = toset(var.control_plane_cidrs)

  security_group_id = aws_security_group.control.id
  description       = "WireGuard from the Coolify control plane"
  cidr_ipv4         = each.value
  ip_protocol       = "udp"
  from_port         = var.wireguard_port
  to_port           = var.wireguard_port
}

resource "aws_vpc_security_group_ingress_rule" "control_from_nodes" {
  for_each = {
    loki      = 3100
    mirror    = 5000
    registry  = 5443
    otlp_grpc = 4317
    otlp_http = 4318
  }

  security_group_id            = aws_security_group.control.id
  description                  = "${each.key} from the execution nodes"
  referenced_security_group_id = aws_security_group.node.id
  ip_protocol                  = "tcp"
  from_port                    = each.value
  to_port                      = each.value
}

resource "aws_vpc_security_group_ingress_rule" "node_ssh_control" {
  security_group_id            = aws_security_group.node.id
  description                  = "SSH from the control node (Ansible bastion and Coolify through WireGuard)"
  referenced_security_group_id = aws_security_group.control.id
  ip_protocol                  = "tcp"
  from_port                    = 22
  to_port                      = 22
}

resource "aws_vpc_security_group_ingress_rule" "node_ssh_control_plane" {
  for_each = toset(var.control_plane_cidrs)

  security_group_id = aws_security_group.node.id
  description       = "SSH from the Coolify control plane (peered networks)"
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 22
  to_port           = 22
}

resource "aws_vpc_security_group_ingress_rule" "node_monitoring" {
  for_each = {
    node_exporter = 9100
    cadvisor      = 9280
    edge_metrics  = 10083
  }

  security_group_id            = aws_security_group.node.id
  description                  = "${each.key} scraped by the regional Prometheus"
  referenced_security_group_id = aws_security_group.control.id
  ip_protocol                  = "tcp"
  from_port                    = each.value
  to_port                      = each.value
}

# The edge router of any node forwards to the Coolify proxy of the node running the host.
resource "aws_vpc_security_group_ingress_rule" "node_proxy" {
  for_each = {
    http  = 80
    https = 443
  }

  security_group_id            = aws_security_group.node.id
  description                  = "Coolify proxy (${each.key}) from the edge routers"
  referenced_security_group_id = aws_security_group.node.id
  ip_protocol                  = "tcp"
  from_port                    = each.value
  to_port                      = each.value
}

# The network load balancer keeps the client address, so the edge ports are open to the internet.
resource "aws_vpc_security_group_ingress_rule" "node_edge" {
  for_each = {
    http  = var.edge_http_port
    https = var.edge_https_port
  }

  security_group_id = aws_security_group.node.id
  description       = "Edge router (${each.key}) behind the regional load balancer"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = each.value
  to_port           = each.value
}

resource "aws_vpc_security_group_ingress_rule" "node_edge_health" {
  security_group_id = aws_security_group.node.id
  description       = "Load balancer health checks"
  cidr_ipv4         = var.vpc_cidr
  ip_protocol       = "tcp"
  from_port         = var.edge_health_port
  to_port           = var.edge_health_port
}

resource "aws_vpc_security_group_ingress_rule" "node_public_databases" {
  count = var.public_database_access ? 1 : 0

  security_group_id = aws_security_group.node.id
  description       = "Tenant databases made public"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = var.public_port_min
  to_port           = var.public_port_max
}

resource "aws_vpc_security_group_egress_rule" "all" {
  for_each = {
    control = aws_security_group.control.id
    node    = aws_security_group.node.id
  }

  security_group_id = each.value
  description       = "Outbound traffic (metadata service and private ranges are filtered on the hosts)"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "-1"
}

resource "aws_vpc_security_group_ingress_rule" "control_otlp_gateway" {
  for_each = var.otlp_domain != "" ? { http = 80, https = 443 } : {}

  security_group_id = aws_security_group.control.id
  description       = "OTLP gateway for tenant traces (${each.key})"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = each.value
  to_port           = each.value
}
