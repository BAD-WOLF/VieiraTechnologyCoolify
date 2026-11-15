variable "region" {
  type    = string
  default = "sa-east-1"
}

variable "vpc_cidr" {
  type    = string
  default = "10.10.0.0/16"
}

variable "ssh_public_key" {
  type = string
}

variable "admin_cidrs" {
  type = list(string)
}

variable "instance_type" {
  description = "Coolify master. 4-8 vCPU and 8-16 GB for ~100 tenants per region."
  type        = string
  default     = "m6i.xlarge"
}

variable "disk_gb" {
  type    = number
  default = 100
}

variable "postgres_version" {
  type    = string
  default = "15"
}

variable "db_instance_class" {
  type    = string
  default = "db.m6g.large"
}

variable "db_backup_days" {
  description = "Daily backups plus point-in-time recovery (WAL) kept by RDS (wiki 6: 30-90 days for data)."
  type        = number
  default     = 30
}

variable "redis_node_type" {
  type    = string
  default = "cache.m6g.large"
}

variable "route53_zone_id" {
  type    = string
  default = ""
}

variable "domain" {
  description = "Dashboard domain, e.g. painel.hostrema.com.br"
  type        = string
  default     = ""
}

variable "instance_count" {
  description = "1 = single master (recovered by AWS). 2 or more = active/active cluster behind an Application Load Balancer, every instance serving the dashboard, the API, the queues and the scheduler (one instance per task)."
  type        = number
  default     = 1

  validation {
    condition     = var.instance_count >= 1
    error_message = "At least one instance."
  }
}

variable "image" {
  description = "Hostrema image used by the cluster instances."
  type        = string
  default     = "ghcr.io/bad-wolf/hostrema:latest"
}

variable "certificate_arn" {
  description = "ACM certificate of the dashboard domain (cluster). Empty = requested and validated through route53_zone_id."
  type        = string
  default     = ""
}

variable "registry_username" {
  description = "GitHub user to pull a private image (cluster)."
  type        = string
  default     = ""
}

variable "registry_password" {
  description = "Token with read:packages for a private image (cluster)."
  type        = string
  default     = ""
  sensitive   = true
}

variable "extra_env" {
  description = "Extra lines of the cluster .env (PLATFORM_EDGE_TOKEN, PLATFORM_METRICS_TOKEN, MAIL_*, ...)."
  type        = map(string)
  default     = {}
  sensitive   = true
}
