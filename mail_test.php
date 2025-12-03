<?php
require_once "notify_helpers.php";

if (ipx_send_email("test@example.com", "Test Email", "It works!")) {
    echo "Success!";
} else {
    echo "Failed!";
}