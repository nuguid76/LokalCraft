<?php
// Keep existing About Us links valid. Home and About now share one scrollable page.
header('Location: index.php#about', true, 302);
exit;
