output "public_ip" {
  description = "Single master: IP of the dashboard (point the domain here)."
  value       = local.cluster ? null : aws_eip.master[0].public_ip
}

output "load_balancer_dns_name" {
  description = "Cluster: point the dashboard domain here (CNAME) when Route53 is not used."
  value       = local.cluster ? aws_lb.panel[0].dns_name : null
}

output "region_peering" {
  description = "Value of control_plane_peering in each region (infra/terraform/regions/*): private link to the nodes."
  value = {
    vpc_id          = aws_vpc.this.id
    cidr            = aws_vpc.this.cidr_block
    region          = var.region
    route_table_ids = [aws_route_table.public.id]
  }
}

output "ssh_hosts" {
  description = "Cluster: SSH addresses of the instances, for the CONTROL_PLANE_HOST variable of the release workflow."
  value       = local.cluster ? join(" ", formatlist("ubuntu@%s", concat(aws_instance.cluster[*].public_ip, aws_instance.worker[*].public_ip))) : null
}

output "env" {
  description = "Lines for /data/coolify/source/.env of the Coolify master (external PostgreSQL and Redis)."
  sensitive   = true
  value       = <<-EOT
    DB_HOST=${aws_db_instance.coolify.address}
    DB_PORT=${aws_db_instance.coolify.port}
    DB_DATABASE=coolify
    DB_USERNAME=coolify
    DB_PASSWORD=${random_password.db.result}
    REDIS_HOST=${aws_elasticache_replication_group.redis.primary_endpoint_address}
    REDIS_PORT=6379
  EOT
}

output "dr_backups_arn" {
  description = "Replicated automated backups in dr_region (restore with aws rds restore-db-instance-to-point-in-time --source-db-instance-automated-backups-arn)."
  value       = var.dr_region != "" ? aws_db_instance_automated_backups_replication.dr[0].id : null
}
