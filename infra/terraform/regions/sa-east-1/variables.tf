variable "project" {
  type    = string
  default = "hostrema"
}

variable "name_prefix" {
  type    = string
  default = "hostrema"
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}

variable "ssh_public_key" {
  description = "Operator SSH public key used by Ansible."
  type        = string
}

variable "admin_cidrs" {
  description = "Administrator networks (SSH bastion)."
  type        = list(string)
}

variable "control_plane_cidrs" {
  description = "Public address(es) of the Coolify control plane, as /32."
  type        = list(string)
}

variable "control_plane_peering" {
  description = "Output region_peering of infra/terraform/control-plane: peers this region with the control plane VPC."
  type = object({
    vpc_id          = string
    cidr            = string
    region          = string
    route_table_ids = list(string)
  })
  default = null
}

variable "node_count" {
  type    = number
  default = 2
}

variable "node_instance_type" {
  type    = string
  default = "m6i.8xlarge"
}

variable "node_data_volume_gb" {
  type    = number
  default = 1000
}

variable "control_instance_type" {
  type    = string
  default = "m6i.2xlarge"
}

variable "public_database_access" {
  type    = bool
  default = false
}

variable "route53_zone_id" {
  type    = string
  default = ""
}

variable "apps_domain" {
  type    = string
  default = ""
}

variable "edge_domain" {
  type    = string
  default = ""
}

variable "nodes_domain" {
  type    = string
  default = ""
}

variable "backup_retention_days" {
  type    = number
  default = 90
}

variable "backup_replication_bucket_arn" {
  description = "Bucket in another region (e.g. us-east-1) for cross-region copies of the backups."
  type        = string
  default     = ""
}

variable "otlp_domain" {
  description = "Public OTLP endpoint for tenant traces, e.g. otlp.sa-east-1.hostrema.com.br."
  type        = string
  default     = ""
}

variable "dedicated_nodes" {
  description = "Enterprise tenants with their own VM: name => { team_id, instance_type, data_volume_gb }."
  type = map(object({
    team_id        = number
    instance_type  = optional(string)
    data_volume_gb = optional(number)
  }))
  default = {}
}
