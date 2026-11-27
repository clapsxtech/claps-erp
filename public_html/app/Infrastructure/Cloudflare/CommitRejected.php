<?php
namespace App\Infrastructure\Cloudflare;

/** D1 definitely did not apply the change: it was refused before or during its atomic batch. Safe to retry. */
class CommitRejected extends \RuntimeException {}
