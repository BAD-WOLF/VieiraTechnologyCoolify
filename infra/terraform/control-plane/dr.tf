# Disaster recovery in a second region (wiki 9, "Ausência de Multi-Region"): RDS copies the
# automated backups and the transaction logs of the control plane database to dr_region, so the
# dashboard can be restored there at any point in time if the whole primary region is lost.
# Tenant data stays in the regions (backups replicated by backup_replication_bucket_arn).
provider "aws" {
  alias  = "dr"
  region = var.dr_region != "" ? var.dr_region : var.region

  default_tags {
    tags = {
      Project = "hostrema"
      Role    = "control-plane-dr"
    }
  }
}

resource "aws_kms_key" "dr" {
  provider = aws.dr
  count    = var.dr_region != "" ? 1 : 0

  description             = "Hostrema control plane database backups replicated from ${var.region}"
  enable_key_rotation     = true
  deletion_window_in_days = 30
}

resource "aws_db_instance_automated_backups_replication" "dr" {
  provider = aws.dr
  count    = var.dr_region != "" ? 1 : 0

  source_db_instance_arn = aws_db_instance.coolify.arn
  kms_key_id             = aws_kms_key.dr[0].arn
  retention_period       = var.db_backup_days

  lifecycle {
    precondition {
      condition     = var.dr_region != var.region
      error_message = "dr_region must be a different region."
    }
  }
}
