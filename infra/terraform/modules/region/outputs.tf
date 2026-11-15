output "vpc_id" {
  value = aws_vpc.this.id
}

output "vpc_cidr" {
  value = aws_vpc.this.cidr_block
}

output "public_subnet_ids" {
  value = aws_subnet.public[*].id
}

output "private_subnet_ids" {
  value = aws_subnet.private[*].id
}

output "control_node" {
  value = {
    id             = aws_instance.control.id
    name           = aws_instance.control.tags["Name"]
    private_ip     = aws_instance.control.private_ip
    public_ip      = aws_eip.control.public_ip
    data_volume_id = aws_ebs_volume.control_data.id
  }
}

output "nodes" {
  value = [
    for index, node in aws_instance.node : {
      id             = node.id
      name           = node.tags["Name"]
      private_ip     = node.private_ip
      public_ip      = var.public_database_access ? aws_eip.node[index].public_ip : ""
      data_volume_id = aws_ebs_volume.node_data[index].id
    }
  ]
}

output "dedicated_nodes" {
  value = {
    for name, node in aws_instance.dedicated : name => {
      id         = node.id
      private_ip = node.private_ip
      team_id    = var.dedicated_nodes[name].team_id
    }
  }
}

output "edge_lb_dns_name" {
  description = "Point *.apps_domain (and the CNAME target of custom domains) here when DNS is not managed by Route53."
  value       = aws_lb.edge.dns_name
}

output "edge_lb_zone_id" {
  value = aws_lb.edge.zone_id
}

output "backup_bucket" {
  value = aws_s3_bucket.backups.bucket
}

output "ansible_inventory" {
  description = "Inventory for infra/ansible (written to infra/ansible/inventory/<region>.yml by the region root module)."
  value = templatefile("${path.module}/templates/inventory.yml.tftpl", {
    platform_region = local.platform_region
    aws_region      = var.region
    vpc_cidr        = var.vpc_cidr
    backup_bucket   = aws_s3_bucket.backups.bucket
    edge_http_port  = var.edge_http_port
    edge_https_port = var.edge_https_port
    edge_health     = var.edge_health_port
    control = {
      name           = aws_instance.control.tags["Name"]
      private_ip     = aws_instance.control.private_ip
      public_ip      = aws_eip.control.public_ip
      data_volume_id = aws_ebs_volume.control_data.id
    }
    nodes = [
      for index, node in aws_instance.node : {
        name           = node.tags["Name"]
        private_ip     = node.private_ip
        public_ip      = var.public_database_access ? aws_eip.node[index].public_ip : ""
        data_volume_id = aws_ebs_volume.node_data[index].id
      }
    ]
    dedicated = [
      for name, node in aws_instance.dedicated : {
        name           = node.tags["Name"]
        private_ip     = node.private_ip
        public_ip      = var.public_database_access ? aws_eip.dedicated[name].public_ip : ""
        data_volume_id = aws_ebs_volume.dedicated_data[name].id
        team_id        = var.dedicated_nodes[name].team_id
      }
    ]
  })
}
