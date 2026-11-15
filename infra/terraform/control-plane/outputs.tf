output "public_ip" {
  description = "Single master: IP of the dashboard (point the domain here)."
  value       = local.cluster ? null : aws_eip.master[0].public_ip
}

output "load_balancer_dns_name" {
  description = "Cluster: point the dashboard domain here (CNAME) when Route53 is not used."
  value       = local.cluster ? aws_lb.panel[0].dns_name : null
}

output "ssh_hosts" {
  description = "Cluster: SSH addresses of the instances, for the CONTROL_PLANE_HOST variable of the release workflow."
  value       = local.cluster ? join(" ", formatlist("ubuntu@%s", aws_instance.cluster[*].public_ip)) : null
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
