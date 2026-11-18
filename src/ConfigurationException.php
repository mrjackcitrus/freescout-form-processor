<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * A configuration problem whose message is safe to show in an HTTP response.
 */
final class ConfigurationException extends RuntimeException {}
