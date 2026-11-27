<?php
namespace App\Infrastructure\Cloudflare;

/** The storage Worker answered and refused the request (HTTP 400 or 409), so nothing was applied. */
class GatewayRejected extends \RuntimeException {}
