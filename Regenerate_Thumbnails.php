<?php

namespace vnh;

/**
 * Simple but effectively resize images on the fly. Doesn't up size, just downsizes like how WordPress likes it.
 * If the image already exists, it's served. If not, the image is resized to the specified size, saved for
 * future use, then served.
 *
 * @package vnh
 */
class Regenerate_Thumbnails {
	public function __construct() {
		add_filter('image_downsize', [$this, 'media_downsize'], 10, 3);
	}

	/**
	 * The downsizer. This only does something if the existing image size doesn't exist yet.
	 *
	 * @param   $out  boolean false
	 * @param   $id   int Attachment ID
	 * @param   $size mixed The size name, or an array containing the width & height
	 *
	 * @return   mixed False if the custom downsize failed, or an array of the image if successful
	 */
	public function media_downsize($out, $id, $size) {
		// Gather all the different image sizes of WP (thumbnail, medium, large) and,
		// all the theme/plugin-introduced sizes.
		global $_vnh_regen_thumbs_all_image_sizes;

		if (!isset($_vnh_regen_thumbs_all_image_sizes)) {
			global $_wp_additional_image_sizes;

			$_vnh_regen_thumbs_all_image_sizes = [];
			$interimSizes = get_intermediate_image_sizes();

			foreach ($interimSizes as $sizeName) {
				if (in_array($sizeName, ['thumbnail', 'medium', 'large'])) {
					$_vnh_regen_thumbs_all_image_sizes[$sizeName]['width'] = get_option($sizeName . '_size_w');
					$_vnh_regen_thumbs_all_image_sizes[$sizeName]['height'] = get_option($sizeName . '_size_h');
					$_vnh_regen_thumbs_all_image_sizes[$sizeName]['crop'] = (bool) get_option($sizeName . '_crop');
				} elseif (isset($_wp_additional_image_sizes[$sizeName])) {
					$_vnh_regen_thumbs_all_image_sizes[$sizeName] = $_wp_additional_image_sizes[$sizeName];
				}
			}
		}

		// This now contains all the data that we have for all the image sizes
		$allSizes = $_vnh_regen_thumbs_all_image_sizes;

		// If image size exists let WP serve it like normally
		$image_data = wp_get_attachment_metadata($id);

		// Image attachment doesn't exist
		if (!is_array($image_data)) {
			return false;
		}

		// If the size given is a string / a name of a size
		if (is_string($size)) {
			// If WP doesn't know about the image size name, then we can't really do any resizing of our own
			if (empty($allSizes[$size])) {
				return false;
			}

			// If the size has already been previously created, use it
			if (!empty($image_data['sizes'][$size]) && !empty($allSizes[$size])) {
				// But only if the size remained the same
				if (
					$allSizes[$size]['width'] == $image_data['sizes'][$size]['width'] &&
					$allSizes[$size]['height'] == $image_data['sizes'][$size]['height']
				) {
					return false;
				}

				// Or if the size is different and we found out before that the size really was different
				if (!empty($image_data['sizes'][$size]['width_query']) && !empty($image_data['sizes'][$size]['height_query'])) {
					if (
						$image_data['sizes'][$size]['width_query'] == $allSizes[$size]['width'] &&
						$image_data['sizes'][$size]['height_query'] == $allSizes[$size]['height']
					) {
						return false;
					}
				}
			}

			// Resize the image
			$resized = image_make_intermediate_size(
				get_attached_file($id),
				$allSizes[$size]['width'],
				$allSizes[$size]['height'],
				$allSizes[$size]['crop']
			);

			// Resize somehow failed
			if (!$resized) {
				return false;
			}

			// Save the new size in WP
			$image_data['sizes'][$size] = $resized;

			// Save some additional info so that we'll know next time whether we've resized this before
			$image_data['sizes'][$size]['width_query'] = $allSizes[$size]['width'];
			$image_data['sizes'][$size]['height_query'] = $allSizes[$size]['height'];

			wp_update_attachment_metadata($id, $image_data);

			// Serve the resized image
			$att_url = wp_get_attachment_url($id);

			return [dirname($att_url) . '/' . $resized['file'], $resized['width'], $resized['height'], true];
			// If the size given is a custom array size
		} elseif (is_array($size)) {
			$imagePath = get_attached_file($id);

			// This would be the path of our resized image if the dimensions existed
			$imageExt = pathinfo($imagePath, PATHINFO_EXTENSION);
			$imagePath = preg_replace('/^(.*)\.' . $imageExt . '$/', sprintf('$1-%sx%s.%s', $size[0], $size[1], $imageExt), $imagePath);

			$att_url = wp_get_attachment_url($id);

			// If it already exists, serve it
			if (file_exists($imagePath)) {
				return [dirname($att_url) . '/' . basename($imagePath), $size[0], $size[1], true];
			}

			// If not, resize the image...
			$resized = image_make_intermediate_size(get_attached_file($id), $size[0], $size[1], true);

			// Get attachment meta so we can add new size
			$image_data = wp_get_attachment_metadata($id);

			// Save the new size in WP so that it can also perform actions on it
			$image_data['sizes'][$size[0] . 'x' . $size[1]] = $resized;
			wp_update_attachment_metadata($id, $image_data);

			// Resize somehow failed
			if (!$resized) {
				return false;
			}

			// Then serve it
			return [dirname($att_url) . '/' . $resized['file'], $resized['width'], $resized['height'], true];
		}

		return false;
	}
}

new Regenerate_Thumbnails();
