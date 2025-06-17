<?php

function webp_image_formatter($upload)
{
  if (get_option('image_formatter_module') !== 'yes') {
    return $upload;
  }

  $file_path = $upload['file'];
  $file_type = wp_check_filetype($file_path);
  $upload_dir = wp_upload_dir();
  $dir = dirname($file_path);
  $filename_wo_ext = pathinfo($file_path, PATHINFO_FILENAME);
  $ext = strtolower($file_type['ext']);

  $timestamp = time();
  $new_filename = $filename_wo_ext . '-' . $timestamp . '.webp';
  $new_path = $dir . '/' . $new_filename;

  if ($ext === 'webp') {
    if (rename($file_path, $new_path)) {
      $upload['file'] = $new_path;
      $upload['url'] = $upload_dir['url'] . '/' . $new_filename;
      $upload['type'] = 'image/webp';
      $upload['name'] = $new_filename;
    } else {
      error_log("Failed to rename WebP file: $file_path");
    }
    return $upload;
  }

  // === Otherwise, convert JPEG/PNG to WebP ===
  if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
    // Load the image
    if ($ext === 'png') {
      $image = imagecreatefrompng($file_path);
    } else {
      $image = imagecreatefromjpeg($file_path);
    }

    if (!$image) {
      error_log("Failed to load image for WebP conversion: $file_path");
      return $upload;
    }

    // Convert and save
    imagewebp($image, $new_path, 80);
    imagedestroy($image);

    if (file_exists($new_path)) {
      unlink($file_path); // remove original

      $upload['file'] = $new_path;
      $upload['url'] = $upload_dir['url'] . '/' . $new_filename;
      $upload['type'] = 'image/webp';
      $upload['name'] = $new_filename;
    } else {
      error_log("WebP conversion failed for: $file_path");
    }
  }

  return $upload;
}

add_filter('wp_handle_upload', 'webp_image_formatter');
