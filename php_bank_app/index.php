<?php
declare(strict_types=1);

// Compatibility entry point when Apache points to the project root.
// Recommended production setup: configure DocumentRoot directly to /public.
header('Location: public/', true, 302);
exit;
