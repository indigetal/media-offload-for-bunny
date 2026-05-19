<?php

namespace Bunny_Offload\Utils;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Constants {
    const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500MB limit
}
