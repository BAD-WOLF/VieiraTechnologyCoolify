output "public_ip" {
  value = aws_eip.master.public_ip
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
