# Private link between the Coolify control plane and the region (wiki 6). The control plane reaches
# the nodes over SSH through the peering, so every instance of a control plane cluster works without
# a tunnel or fixed public addresses. Only SSH is allowed on the nodes (security.tf), and the tenant
# containers can not reach the control plane network (Ansible role firewall).
locals {
  peering = var.control_plane_peering != null
}

resource "aws_vpc_peering_connection" "control_plane" {
  count = local.peering ? 1 : 0

  vpc_id      = aws_vpc.this.id
  peer_vpc_id = var.control_plane_peering.vpc_id
  peer_region = var.control_plane_peering.region
  auto_accept = false

  tags = merge(local.tags, { Name = "${local.name}-control-plane" })
}

resource "aws_vpc_peering_connection_accepter" "control_plane" {
  provider = aws.control_plane
  count    = local.peering ? 1 : 0

  vpc_peering_connection_id = aws_vpc_peering_connection.control_plane[0].id
  auto_accept               = true

  tags = merge(local.tags, { Name = "${local.name}-control-plane" })
}

resource "aws_route" "to_control_plane" {
  for_each = local.peering ? { public = aws_route_table.public.id, private = aws_route_table.private.id } : {}

  route_table_id            = each.value
  destination_cidr_block    = var.control_plane_peering.cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.control_plane[0].id

  depends_on = [aws_vpc_peering_connection_accepter.control_plane]
}

resource "aws_route" "from_control_plane" {
  provider = aws.control_plane
  for_each = toset(local.peering ? var.control_plane_peering.route_table_ids : [])

  route_table_id            = each.value
  destination_cidr_block    = var.vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.control_plane[0].id

  depends_on = [aws_vpc_peering_connection_accepter.control_plane]
}
