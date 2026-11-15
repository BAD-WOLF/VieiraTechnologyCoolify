locals {
  manage_dns = var.route53_zone_id != ""
}

# *.apps_domain -> regional load balancer. With latency routing every region adds its own record
# under the same name and Route53 answers with the closest healthy region (wiki 2 and 6).
resource "aws_route53_record" "apps_wildcard" {
  count = local.manage_dns && var.apps_domain != "" ? 1 : 0

  zone_id        = var.route53_zone_id
  name           = "*.${var.apps_domain}"
  type           = "A"
  set_identifier = var.latency_routing ? local.platform_region : null

  dynamic "latency_routing_policy" {
    for_each = var.latency_routing ? [1] : []

    content {
      region = var.region
    }
  }

  alias {
    name                   = aws_lb.edge.dns_name
    zone_id                = aws_lb.edge.zone_id
    evaluate_target_health = true
  }
}

resource "aws_route53_record" "edge" {
  count = local.manage_dns && var.edge_domain != "" ? 1 : 0

  zone_id = var.route53_zone_id
  name    = var.edge_domain
  type    = "A"

  alias {
    name                   = aws_lb.edge.dns_name
    zone_id                = aws_lb.edge.zone_id
    evaluate_target_health = true
  }
}

resource "aws_route53_record" "node" {
  count = local.manage_dns && var.public_database_access && var.nodes_domain != "" ? var.node_count : 0

  zone_id = var.route53_zone_id
  name    = format("node-%02d.%s", count.index + 1, var.nodes_domain)
  type    = "A"
  ttl     = 300
  records = [aws_eip.node[count.index].public_ip]
}
