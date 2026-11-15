variable "region" {
  description = "AWS region of the platform region (e.g. sa-east-1)."
  type        = string
}

variable "platform_region" {
  description = "Slug of the region inside Coolify (PlatformRegion). Defaults to the AWS region."
  type        = string
  default     = ""
}

variable "name_prefix" {
  description = "Prefix of every resource name."
  type        = string
  default     = "hostrema"
}

variable "vpc_cidr" {
  description = "CIDR of the region VPC. Public subnets use the first /20 blocks and private subnets start at the 9th."
  type        = string
  default     = "10.20.0.0/16"
}

variable "availability_zones" {
  description = "Availability zones to use. Empty = the first az_count zones of the region."
  type        = list(string)
  default     = []
}

variable "az_count" {
  description = "Number of availability zones when availability_zones is empty."
  type        = number
  default     = 2
}

variable "ami_id" {
  description = "AMI of the instances. Empty = latest Ubuntu 24.04 LTS (Canonical)."
  type        = string
  default     = ""
}

variable "ssh_public_key" {
  description = "Operator SSH public key (Ansible). The Coolify control plane key is authorized later by Ansible."
  type        = string
}

variable "admin_cidrs" {
  description = "CIDRs allowed to SSH into the control node (bastion). Grafana, Prometheus and Alertmanager listen on localhost: use an SSH tunnel."
  type        = list(string)
}

variable "control_plane_cidrs" {
  description = "CIDRs of the Coolify control plane (master). Allowed to reach WireGuard on the control node and SSH on the nodes (peered networks)."
  type        = list(string)
}

variable "control_plane_peering" {
  description = <<-EOT
    VPC of the Coolify control plane (output region_peering of infra/terraform/control-plane). When set,
    the region VPC is peered with it, routes are added on both sides and the control plane reaches the
    nodes over the peering (no WireGuard needed). Required for a control plane cluster. The CIDRs must not overlap.
  EOT
  type = object({
    vpc_id          = string
    cidr            = string
    region          = string
    route_table_ids = list(string)
  })
  default = null
}

variable "wireguard_port" {
  description = "UDP port of the WireGuard tunnel between the Coolify control plane and the region."
  type        = number
  default     = 51820
}

variable "control_instance_type" {
  description = "Instance type of the region control node (monitoring, registry mirror, bastion). Wiki: 8-16 vCPU, 32-64 GB."
  type        = string
  default     = "m6i.2xlarge"
}

variable "control_data_volume_gb" {
  description = "Data volume of the control node (Prometheus, Loki, registry cache)."
  type        = number
  default     = 1000
}

variable "node_count" {
  description = "Number of execution nodes (minimum 2 for high availability)."
  type        = number
  default     = 2

  validation {
    condition     = var.node_count >= 1
    error_message = "At least one execution node is required."
  }
}

variable "node_instance_type" {
  description = "Instance type of the execution nodes. Wiki: 24-32 vCPU, 128-192 GB."
  type        = string
  default     = "m6i.8xlarge"
}

variable "node_root_volume_gb" {
  description = "Root volume of the execution nodes."
  type        = number
  default     = 100
}

variable "node_data_volume_gb" {
  description = "Data volume of the execution nodes (/data: Docker, images and tenant volumes)."
  type        = number
  default     = 1000
}

variable "node_data_volume_iops" {
  description = "Provisioned IOPS of the node data volumes (gp3)."
  type        = number
  default     = 6000
}

variable "node_data_volume_throughput" {
  description = "Provisioned throughput (MB/s) of the node data volumes (gp3)."
  type        = number
  default     = 500
}

variable "public_database_access" {
  description = "Place the nodes in public subnets with Elastic IPs so tenants can reach databases made public (ports public_port_min..public_port_max)."
  type        = bool
  default     = false
}

variable "public_port_min" {
  description = "First public database port (config platform.container.public_port_min)."
  type        = number
  default     = 20000
}

variable "public_port_max" {
  description = "Last public database port (config platform.container.public_port_max)."
  type        = number
  default     = 29999
}

variable "edge_http_port" {
  description = "Port of the edge router for HTTP on each node (the load balancer forwards :80 here)."
  type        = number
  default     = 10080
}

variable "edge_https_port" {
  description = "Port of the edge router for HTTPS passthrough on each node (the load balancer forwards :443 here)."
  type        = number
  default     = 10443
}

variable "edge_health_port" {
  description = "Port of the edge router health check (Traefik ping)."
  type        = number
  default     = 10082
}

variable "route53_zone_id" {
  description = "Route53 hosted zone for the region records. Empty = manage DNS elsewhere (e.g. Cloudflare)."
  type        = string
  default     = ""
}

variable "apps_domain" {
  description = "Domain of the tenant applications (wildcard record *.apps_domain -> regional load balancer), e.g. apps.example.com."
  type        = string
  default     = ""
}

variable "edge_domain" {
  description = "Record that tenants use as CNAME target of their custom domains, e.g. edge.sa-east-1.example.com."
  type        = string
  default     = ""
}

variable "nodes_domain" {
  description = "Domain of the per-node records (node-01.nodes_domain) when public_database_access is enabled."
  type        = string
  default     = ""
}

variable "latency_routing" {
  description = "Create the wildcard record with Route53 latency routing (one record per region, wiki: georouting)."
  type        = bool
  default     = true
}

variable "backup_bucket_name" {
  description = "Name of the regional backup bucket. Empty = <prefix>-<region>-backups-<account id>."
  type        = string
  default     = ""
}

variable "backup_retention_days" {
  description = "Days the backups are kept in the regional bucket."
  type        = number
  default     = 90
}

variable "backup_replication_bucket_arn" {
  description = "ARN of a versioned bucket in another region for cross-region replication of the backups. Empty = disabled."
  type        = string
  default     = ""
}

variable "snapshot_retention_count" {
  description = "Daily EBS snapshots kept for the data volumes."
  type        = number
  default     = 7
}

variable "tags" {
  description = "Extra tags of every resource."
  type        = map(string)
  default     = {}
}

variable "otlp_domain" {
  description = "Public OTLP endpoint of the region for tenant traces (e.g. otlp.sa-east-1.hostrema.com.br): opens 80/443 on the control node. Empty = disabled."
  type        = string
  default     = ""
}

variable "dedicated_nodes" {
  description = "Enterprise tenants with their own VM (wiki 3, VM isolation): name => { team_id, instance_type, data_volume_gb }. The node is registered as dedicated to the team and never receives other tenants."
  type = map(object({
    team_id        = number
    instance_type  = optional(string)
    data_volume_gb = optional(number)
  }))
  default = {}

  validation {
    condition     = alltrue([for name, node in var.dedicated_nodes : can(regex("^[a-z0-9-]{1,30}$", name)) && node.team_id > 0])
    error_message = "Use lowercase names (a-z, 0-9, -) and team ids greater than 0."
  }
}
