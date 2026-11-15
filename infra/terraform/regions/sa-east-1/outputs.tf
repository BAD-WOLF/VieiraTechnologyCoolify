output "control_node" {
  value = module.region.control_node
}

output "nodes" {
  value = module.region.nodes
}

output "edge_lb_dns_name" {
  value = module.region.edge_lb_dns_name
}

output "control_plane_peering_id" {
  value = module.region.control_plane_peering_id
}

output "backup_bucket" {
  value = module.region.backup_bucket
}

output "inventory_file" {
  value = local_file.ansible_inventory.filename
}
