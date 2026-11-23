# Region template (wiki 2): `terraform apply` here + `ansible-playbook` in infra/ansible bring a
# region from nothing to nodes registered in Coolify. Copy this folder for a new region.
terraform {
  required_version = ">= 1.6"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = ">= 5.40"
    }
    local = {
      source  = "hashicorp/local"
      version = ">= 2.4"
    }
  }
}

provider "aws" {
  region = "sa-east-1"

  default_tags {
    tags = {
      Project = var.project
      Region  = "sa-east-1"
    }
  }
}

# Region of the Coolify control plane, for the VPC peering (control_plane_peering).
provider "aws" {
  alias  = "control_plane"
  region = try(var.control_plane_peering.region, "sa-east-1")

  default_tags {
    tags = {
      Project = var.project
      Region  = "sa-east-1"
    }
  }
}

module "region" {
  source = "../../modules/region"

  providers = {
    aws               = aws
    aws.control_plane = aws.control_plane
  }

  region                        = "sa-east-1"
  platform_region               = "sa-east-1"
  name_prefix                   = var.name_prefix
  availability_zones            = var.availability_zones
  vpc_cidr                      = var.vpc_cidr
  ssh_public_key                = var.ssh_public_key
  admin_cidrs                   = var.admin_cidrs
  control_plane_cidrs           = var.control_plane_cidrs
  control_plane_peering         = var.control_plane_peering
  node_count                    = var.node_count
  node_instance_type            = var.node_instance_type
  node_data_volume_gb           = var.node_data_volume_gb
  node_data_volume_iops         = var.node_data_volume_iops
  node_data_volume_throughput   = var.node_data_volume_throughput
  control_instance_type         = var.control_instance_type
  control_data_volume_gb        = var.control_data_volume_gb
  public_database_access        = var.public_database_access
  route53_zone_id               = var.route53_zone_id
  apps_domain                   = var.apps_domain
  edge_domain                   = var.edge_domain
  nodes_domain                  = var.nodes_domain
  otlp_domain                   = var.otlp_domain
  dedicated_nodes               = var.dedicated_nodes
  backup_retention_days         = var.backup_retention_days
  backup_replication_bucket_arn = var.backup_replication_bucket_arn
}

# Terraform outputs consumed by Ansible and the pipelines (wiki 5, "Integração com IaC").
resource "local_file" "ansible_inventory" {
  filename        = "${path.module}/../../../ansible/inventory/sa-east-1.yml"
  content         = module.region.ansible_inventory
  file_permission = "0640"
}
