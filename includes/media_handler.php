<?php
// includes/media_handler.php

function saveBase64Image($base64String, $subfolder = 'signatures') {
    if (empty($base64String)) return null;

    // Ensure directory exists
    $target_dir = __DIR__ . '/../uploads/' . $subfolder . '/';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    // Check if it's a valid data URL
    if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
        $data = substr($base64String, strpos($base64String, ',') + 1);
        $type = strtolower($type[1]); // jpg, png, jpeg

        if (!in_array($type, ['jpg', 'jpeg', 'png', 'webp'])) {
            throw new Exception('Invalid image type.');
        }

        $data = base64_decode($data);
        if ($data === false) {
            throw new Exception('Base64 decode failed.');
        }

        // Generate unique filename
        $filename = uniqid(time() . '_') . '.' . $type;
        $filepath = $target_dir . $filename;

        // Save file
        file_put_contents($filepath, $data);

        // Return relative path for database
        return 'uploads/' . $subfolder . '/' . $filename;
    }
    return null;
}
?>