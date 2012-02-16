<?php 
/** 
 * Uploader
 *
 * A class that will upload a wide range of file types. Security and type checking have been integrated to only allow valid files. 
 * The class can also handle advanced image transforming.
 *
 * @author      Miles Johnson - http://milesj.me
 * @copyright   Copyright 2006-2011, Miles Johnson, Inc.
 * @license     http://opensource.org/licenses/mit-license.php - Licensed under The MIT License
 * @link        http://milesj.me/code/cakephp/uploader
 */

Configure::load('Uploader.config');

class Uploader {

	/**
	 * The direction to flip: vertical.
	 *
	 * @constant
	 * @var int
	 */
	const DIR_VERT = 1;

	/**
	 * The direction to flip: horizontal.
	 *
	 * @constant
	 * @var int
	 */
	const DIR_HORI = 2;

	/**
	 * The direction to flip: vertical and horizontal.
	 *
	 * @constant
	 * @var int
	 */
	const DIR_BOTH = 3;

	/**
	 * The location to crop: top.
	 *
	 * @constant
	 * @var int
	 */
	const LOC_TOP = 1;

	/**
	 * The location to crop: bottom.
	 *
	 * @constant
	 * @var int
	 */
	const LOC_BOT = 2;

	/**
	 * The location to crop: left.
	 *
	 * @constant
	 * @var int
	 */
	const LOC_LEFT = 3;

	/**
	 * The location to crop: right.
	 *
	 * @constant
	 * @var int
	 */
	const LOC_RIGHT = 4;

	/**
	 * The location to crop: center.
	 *
	 * @constant
	 * @var int
	 */
	const LOC_CENTER = 5;

	/**
	 * Max filesize using shorthand notation: http://php.net/manual/faq.using.php#faq.using.shorthandbytes
	 *
	 * @access public
	 * @var string
	 */
	public $maxFileSize = '5M';

	/**
	 * How long should file names be?
	 *
	 * @access public
	 * @var int
	 */
	public $maxNameLength = 40;

	/**
	 * Should we scan the file for viruses? Requires ClamAV module: http://clamav.net/
	 *
	 * @access public
	 * @var boolean
	 */
	public $scanFile = false;

	/**
	 * Temp upload directory.
	 *
	 * @access public
	 * @var string
	 */
	public $tempDir = TMP;

	/**
	 * Base upload directory; usually Cake webroot.
	 *
	 * @access public
	 * @var string
	 */
	public $baseDir = WWW_ROOT;

	/**
	 * Destination upload directory within $baseDir.
	 *
	 * @access public
	 * @var string
	 */
	public $uploadDir = 'files/uploads/';
	
	/**
	 * The field name used during AJAX file uploading.
	 * 
	 * @access public
	 * @var string
	 */
	public $ajaxField = '';

	/**
	 * The current file being processed.
	 *
	 * @access protected
	 * @var string
	 */
	protected $_current;

	/**
	 * Holds the current $_FILES data.
	 *
	 * @access protected
	 * @var array
	 */
	protected $_data = array();

	/**
	 * Should we allow file uploading for this request?
	 *
	 * @access protected
	 * @var boolean
	 */
	protected $_enabled = true;

	/**
	 * The final formatted directory.
	 *
	 * @access protected
	 * @var string
	 */
	protected $_finalDir;

	/**
	 * The accepted file/mime types; imported from config.
	 *
	 * @access protected
	 * @var array
	 * @static
	 */
	protected static $_mimeTypes = array();

	/**
	 * Parse the $_FILES data and setup ini settings.
	 *
	 * @access public
	 * @param array $settings
	 */
	public function __construct(array $settings = array()) {
		$this->setup($settings);
		
		if ($this->_loadExtension('gd')) {
			$this->_enabled = ini_get('file_uploads');
		} else {
			$this->_enabled = false;
			trigger_error('Uploader.Uploader::__construct(): GD image library is not installed.', E_USER_WARNING);
		}

		if (!$this->_enabled) {
			return;
		}

		if (empty($this->maxFileSize)) {
			$this->maxFileSize = ini_get('upload_max_filesize');
		}

		$byte = preg_replace('/[^0-9]/i', '', $this->maxFileSize);
		$last = self::bytes($this->maxFileSize, 'byte');

		if ($last == 'T' || $last == 'TB') {
			$multiplier = 1;
			$execTime = 20;
		} else if ($last == 'G' || $last == 'GB') {
			$multiplier = 3;
			$execTime = 10;
		} else if ($last == 'M' || $last == 'MB') {
			$multiplier = 5;
			$execTime = 5;
		} else {
			$multiplier = 10;
			$execTime = 3;
		}

		ini_set('memory_limit', (($byte * $multiplier) * $multiplier) . $last);
		ini_set('post_max_size', ($byte * $multiplier) . $last);
		ini_set('upload_tmp_dir', $this->tempDir);
		ini_set('upload_max_filesize', $this->maxFileSize);
		ini_set('max_execution_time', ($execTime * 10));
		ini_set('max_input_time', ($execTime * 10));

		if (!is_writable($this->tempDir)) {
			chmod($this->tempDir, 0777);
		}

		$this->baseDir = str_replace('\\', '/', $this->baseDir);
		$this->_parseData();
	}

	/**
	 * Adds a mime type to the list of allowed types.
	 *
	 * @access public
	 * @param string $group
	 * @param string $ext
	 * @param string $type
	 * @return void
	 * @static
	 */
	public static function addMimeType($group = null, $ext = null, $type = null) {
		if (empty($group)) {
			$group = 'misc';
		}

		if (!empty($ext) && !empty($type)) {
			if (isset(self::$_mimeTypes[$group][$ext])) {
				if (is_array(self::$_mimeTypes[$group][$ext])) {
					self::$_mimeTypes[$group][$ext][] = $type;
				} else {
					self::$_mimeTypes[$group][$ext] = array(
						self::$_mimeTypes[$group][$ext],
						$type
					);
				}
			} else {
				self::$_mimeTypes[$group][$ext] = $type;
			}
		}
	}

	/**
	 * Return the bytes based off the shorthand notation.
	 *
	 * @access public
	 * @param int $size
	 * @param string $return
	 * @return string
	 * @static
	 */
	public static function bytes($size, $return = null) {
		if (!is_numeric($size)) {
			$byte = preg_replace('/[^0-9]/i', '', $size);
			$last = mb_strtoupper(preg_replace('/[^a-zA-Z]/i', '', $size));

			if ($return == 'byte') {
				return $last;
			}

			switch ($last) {
				case 'T': case 'TB': $byte *= 1024;
				case 'G': case 'GB': $byte *= 1024;
				case 'M': case 'MB': $byte *= 1024;
				case 'K': case 'KB': $byte *= 1024;
			}

			$size = $byte;
		}

		if ($return == 'size') {
			return $size;
		}

		$sizes = array('YB', 'ZB', 'EB', 'PB', 'TB', 'GB', 'MB', 'KB', 'B');
		$total = count($sizes);

		while ($total-- && $size > 1024) {
			$size /= 1024;
		}

		return round($size, 0) . ' ' . $sizes[$total];
	}

	/**
	 * Check the destination folder. If it does not exist or isn't writable, fix it!
	 *
	 * @access public
	 * @param string $dir
	 * @return void
	 */
	public function checkDirectory($dir = null) {
		$uploadDir = trim($dir ? $dir : $this->uploadDir, '/');
		$finalDir = $this->formatPath($uploadDir . '/');

		if (!file_exists($finalDir)) {
			mkdir($finalDir, 0777, true);
			
		} else if (!is_writable($finalDir)) {
			chmod($finalDir, 0777);
		}

		$this->_finalDir = $finalDir;
	}

	/**
	 * Check the extension and mimetype against the supported list. If found, return the grouping.
	 *
	 * @access public
	 * @param string $ext
	 * @param string $type
	 * @return mixed
	 * @static
	 */
	public static function checkMimeType($ext, $type) {
		if (empty(self::$_mimeTypes)) {
			self::$_mimeTypes = Configure::read('Uploader.mimeTypes');
		}
		
		$validExt = false;
		$validMime = false;
		$currType = mb_strtolower($type);
		$group = null;

		foreach (self::$_mimeTypes as $grouping => $mimes) {
			if (isset($mimes[$ext])) {
				$validExt = true;
			}

			foreach ($mimes as $mimeType) {
				if (($currType == $mimeType) || (is_array($mimeType) && in_array($currType, $mimeType))) {
					$validMime = true;
					$group = $grouping;

					break 2;
				}
			}
		}

		if ($validExt && $validMime) {
			return $group;
		}

		return false;
	}

	/**
	 * Crops a photo, resizes first depending on which side is larger.
	 *
	 * @access public
	 * @param array $options
	 *		- location: Which area of the image should be grabbed for the crop: center, left, right, top, bottom
	 *		- width,
	 *		- height: The width and height to resize the image to before cropping
	 *		- append: What should be appended to the end of the filename (defaults to dimensions if not set)
	 *		- prepend: What should be prepended to the front of the filename
	 *		- quality: The quality of the image
	 * @param boolean $explicit
	 * @return mixed
	 */
	public function crop(array $options = array(), $explicit = false) {
		if ($this->_data[$this->_current]['group'] != 'image' || !$this->_enabled) {
			return false;
		}

		$options = $options + array('location' => self::LOC_CENTER, 'quality' => 100, 'width' => null, 'height' => null, 'append' => null, 'prepend' => null);
		$width	= $this->_data[$this->_current]['width'];
		$height = $this->_data[$this->_current]['height'];
		$src_x	= 0;
		$src_y	= 0;
		$dest_w = $width;
		$dest_h = $height;
		$location = $options['location'];

		if (is_numeric($options['width']) && is_numeric($options['height'])) {
			$newWidth = $options['width'];
			$newHeight = $options['height'];

			if ($width > $height) {
				$dest_h = $options['height'];
				$dest_w = round(($width / $height) * $options['height']);
			} else if ($height > $width) {
				$dest_w = $options['width'];
				$dest_h = round(($height / $width) * $options['width']);
			} else {
				$dest_w = $options['width'];
				$dest_h = $options['height'];
			}
		} else {
			if ($width > $height) {
				$newWidth = $height;
				$newHeight = $height;
			} else {
				$newWidth = $width;
				$newHeight = $width;
			}

			$dest_h = $newHeight;
			$dest_w = $newWidth;
		}

		if ($dest_w > $dest_h) {
			if ($location == self::LOC_CENTER) {
				$src_x = ceil(($width - $height) / 2);
				$src_y = 0;
			} else if ($location == self::LOC_BOT || $location == self::LOC_RIGHT) {
				$src_x = ($width - $height);
				$src_y = 0;
			}
		} else if ($dest_h > $dest_w) {
			if ($location == self::LOC_CENTER) {
				$src_x = 0;
				$src_y = ceil(($height - $width) / 2);
			} else if ($location == self::LOC_BOT || $location == self::LOC_RIGHT) {
				$src_x = 0;
				$src_y = ($height - $width);
			}
		}

		$append = '_cropped_' . $newWidth . 'x' . $newHeight;

		if ($options['append'] !== false && empty($options['append'])) {
			$options['append'] = $append;
		}

		$transform = array(
			'width'		=> $newWidth,
			'height'	=> $newHeight,
			'source_x'	=> $src_x,
			'source_y'	=> $src_y,
			'source_w'	=> $width,
			'source_h'	=> $height,
			'dest_w'	=> $dest_w,
			'dest_h'	=> $dest_h,
			'target'	=> $this->setDestination($this->_data[$this->_current]['name'], true, $options, false),
			'quality'	=> $options['quality']
		);

		if ($this->transform($transform)) {
			return $this->_returnData($transform, $append, $explicit);
		}

		return false;
	}

	/**
	 * Deletes a file, path is relative to webroot/.
	 *
	 * @access public
	 * @param string $path
	 * @return boolean
	 */
	public function delete($path) {
		$path = $this->formatPath($path);

		if (file_exists($path)) {
			clearstatcache();
			return unlink($path);
		}

		return false;
	}

	/**
	 * Get the dimensions of an image.
	 *
	 * @access public
	 * @param string $path
	 * @return array
	 */
	public function dimensions($path) {
		$dim = array();

		foreach (array($path, $this->formatPath($path)) as $newPath) {
			$data = @getimagesize($newPath);

			if (!empty($data) && is_array($data)) {
				$dim = array(
					'width' => $data[0],
					'height' => $data[1],
					'type' => $data['mime']
				);
				
				break;
			}
		}

		if (empty($dim)) {
			$image = @imagecreatefromstring(file_get_contents($path));

			$dim = array(
				'width' => @imagesx($image),
				'height' => @imagesy($image),
				'type' => self::mimeType($path)
			);
		}

		return $dim;
	}

	/**
	 * Get the extension.
	 *
	 * @access public
	 * @param string $file
	 * @return string
	 * @static
	 */
	public static function ext($file) {
		return mb_strtolower(trim(mb_strrchr($file, '.'), '.'));
	}

	/**
	 * Flips an image in 3 possible directions.
	 *
	 * @access public
	 * @param array $options
	 *		- dir: The direction the image should be flipped
	 *		- append: What should be appended to the end of the filename (defaults to flip direction if not set)
	 *		- prepend: What should be prepended to the front of the filename
	 *		- quality: The quality of the image
	 * @param boolean $explicit
	 * @return string
	 */
	public function flip(array $options = array(), $explicit = false) {
		if ($this->_data[$this->_current]['group'] != 'image' || !$this->_enabled) {
			return false;
		}

		$options = $options + array('dir' => self::DIR_VERT, 'quality' => 100, 'append' => null, 'prepend' => null);
		$width	= $this->_data[$this->_current]['width'];
		$height = $this->_data[$this->_current]['height'];
		$src_x	= 0;
		$src_y	= 0;
		$src_w	= $width;
		$src_h	= $height;

		switch ($options['dir']) {
			// vertical
			case self::DIR_VERT:
				$src_y = --$height;
				$src_h = -$height;
				$adir = 'vert';
			break;

			// horizontal
			case self::DIR_HORI:
				$src_x = --$width;
				$src_w = -$width;
				$adir = 'hor';
			break;

			// both
			case self::DIR_BOTH:
				$src_x = --$width;
				$src_y = --$height;
				$src_w = -$width;
				$src_h = -$height;
				$adir = 'both';
			break;
			default:
				return false;
			break;
		}

		$append = '_flipped_' . $adir;

		if ($options['append'] !== false && empty($options['append'])) {
			$options['append'] = $append;
		}

		$transform = array(
			'width'		=> $width,
			'height'	=> $height,
			'source_x'	=> $src_x,
			'source_y'	=> $src_y,
			'source_w'	=> $src_w,
			'source_h'	=> $src_h,
			'target'	=> $this->setDestination($this->_data[$this->_current]['name'], true, $options, false),
			'quality'	=> $options['quality']
		);

		if ($this->transform($transform)) {
			return $this->_returnData($transform, $append, $explicit);
		}

		return false;
	}


	/**
	 * Determines the name of the file.
	 *
	 * @access public
	 * @param string $name
	 * @param string $append
	 * @param string $prepend
	 * @param boolean $truncate
	 * @return string
	 */
	public function formatFilename($name = '', $append = '', $prepend = '', $truncate = true) {
		if (empty($name)) {
			$name = $this->_data[$this->_current]['name'];
		}

		// Run the formatter function
		if (is_callable($name)) {
			$name = call_user_func_array($name, array(
				$this->_data[$this->_current]['name'],
				$this->_current,
				$this->_data[$this->_current]
			));

			$this->_data[$this->_current]['custom_name'] = $name;
		}

		$ext = self::ext($this->_data[$this->_current]['name']);

		if (empty($ext)) {
			$ext = $this->_data[$this->_current]['ext'];
		}
		
		$patterns = array('/[^-_.a-zA-Z0-9\/\s]/i', '/[\s]/');
		$name = str_replace('.'. $ext, '', $name);
		$name = preg_replace($patterns, array('', '_'), $name);

		if (is_numeric($this->maxNameLength) && $truncate) {
			if (mb_strlen($name) > $this->maxNameLength) {
				$name = mb_substr($name, 0, $this->maxNameLength);
			}
		}

		$append = (string) $append;
		$prepend = (string) $prepend;

		if (!empty($append)) {
			$append = preg_replace($patterns, array('', '_'), $append);
			$name = $name . $append;
		}

		if (!empty($prepend)) {
			$prepend = preg_replace($patterns, array('', '_'), $prepend);
			$name = $prepend . $name;
		}

		$name = $name . '.' . $ext;
		$name = trim($name, '/');

		return $name;
	}

	/**
	 * Return the path with the base directory if it is absent.
	 *
	 * @access public
	 * @param string $path
	 * @return string
	 */
	public function formatPath($path) {
		if (substr($this->baseDir, -1) != '/') {
			$this->baseDir .= '/';
		}

		if (strpos($path, $this->baseDir) !== 0) {
			$path = $this->baseDir . $path;
		}

		return $path;
	}

	/**
	 * Import a file from the local filesystem and create a copy of it. Requires a full absolute path.
	 *
	 * @access public
	 * @param string $path
	 * @param array $options
	 *		- name: What should the filename be changed to
	 *		- overwrite: Should we overwrite the existant file with the same name?
	 *		- delete: Delete the original file after importing
	 * @return mixed - Array on success, false on failure
	 */
	public function import($path, array $options = array()) {
		if (!$this->_enabled || !file_exists($path)) {
			return false;
		} else {
			$this->checkDirectory();
		}

		$options = $options + array('name' => null, 'overwrite' => false, 'delete' => false);

		$this->_current = basename($path);
		$this->_data[$this->_current]['name'] = $this->_current;
		$this->_data[$this->_current]['path'] = $path;
		$this->_data[$this->_current]['type'] = self::mimeType($path);
		$this->_data[$this->_current]['ext'] = self::ext($path);

		// Validate everything
		if ($this->_validates(true)) {
			if ($this->_data[$this->_current]['group'] == 'image') {
				$dimensions = $this->dimensions($path);

				$this->_data[$this->_current]['width'] = $dimensions['width'];
				$this->_data[$this->_current]['height'] = $dimensions['height'];
			}
		} else {
			return false;
		}

		// Make a copy of the local file
		$dest = $this->setDestination($options['name'], $options['overwrite']);

		if (copy($path, $dest)) {
			$this->_data[$this->_current]['uploaded'] = date('Y-m-d H:i:s');
			$this->_data[$this->_current]['filesize'] = self::bytes(filesize($path));

			if ($options['delete']) {
				@unlink($path);
			}
		} else {
			return false;
		}

		chmod($dest, 0777);
		
		return $this->_returnData();
	}

	/**
	 * Import a file from an external remote URL. Must be an absolute URL.
	 *
	 * @access public
	 * @param string $url
	 * @param array $options
	 *		- name: What should the filename be changed to
	 *		- overwrite: Should we overwrite the existant file with the same name?
	 * @return mixed - Array on success, false on failure
	 */
	public function importRemote($url, array $options = array()) {
		if (!$this->_enabled) {
			return false;
		} else {
			$this->checkDirectory();
		}

		$options = $options + array('name' => null, 'overwrite' => false);

		$this->_current = basename($url);
		$this->_data[$this->_current]['name'] = $this->_current;
		$this->_data[$this->_current]['path'] = $url;
		$this->_data[$this->_current]['type'] = self::mimeType($url);
		$this->_data[$this->_current]['ext'] = self::ext($url);
		
		// Validate everything
		if (!$this->_validates(true)) {
			return false;
		}

		// Make a copy of the remote file
		$dest = $this->setDestination($options['name'], $options['overwrite']);

		if (file_put_contents($dest, file_get_contents($url))) {
			$this->_data[$this->_current]['uploaded'] = date('Y-m-d H:i:s');
			$this->_data[$this->_current]['filesize'] = self::bytes(filesize($dest));

			if ($this->_data[$this->_current]['group'] == 'image') {
				$dimensions = $this->dimensions($dest);

				$this->_data[$this->_current]['width'] = $dimensions['width'];
				$this->_data[$this->_current]['height'] = $dimensions['height'];
			}
		} else {
			return false;
		}

		chmod($dest, 0777);
		
		return $this->_returnData();
	}

	/**
	 * Returns the mimetype of a given file.
	 *
	 * @access public
	 * @param string $path
	 * @return string
	 * @static
	 */
	public static function mimeType($path) {
		if (function_exists('mime_content_type') && file_exists($path)) {
			return mime_content_type($path);
		}
		
		if (empty(self::$_mimeTypes)) {
			self::$_mimeTypes = Configure::read('Uploader.mimeTypes');
		}

		$ext = self::ext($path);
		$type = null;

		foreach (self::$_mimeTypes as $group => $mimes) {
			if (in_array($ext, array_keys($mimes))) {
				$type = self::$_mimeTypes[$group][$ext];
				break;
			}
		}

		if (is_array($type)) {
			$type = $type[0];
		}

		return $type;
	}

	/**
	 * Move a file to another destination.
	 *
	 * @access public
	 * @param string $origPath
	 * @param string $destPath
	 * @param boolean $overwrite
	 * @return boolean
	 */
	public function move($origPath, $destPath, $overwrite = false) {
		$destFull = $this->formatPath($destPath);
		$origFull = $this->formatPath($origPath);

		if (($origPath === $destPath) || !file_exists($origFull) || !is_writable(dirname($destFull))) {
			return false;
		}

		if ($overwrite) {
			if (file_exists($destFull)) {
				$this->delete($destPath);
			}
		} else {
			if (file_exists($destFull)) {
				$destination = $this->setDestination(basename($destPath), false, array('append' => '_moved'), false);
				rename($destFull, $destination);
			}
		}

		return rename($origFull, $destFull);
	}

	/**
	 * Rename a file / Alias for move().
	 *
	 * @access public
	 * @param string $origPath
	 * @param string $destPath
	 * @param boolean $overwrite
	 * @return boolean
	 */
	public function rename($origPath, $destPath, $overwrite = false) {
		return $this->move($origPath, $destPath, $overwrite);
	}

	/**
	 * Resizes and image based off a previously uploaded image.
	 *
	 * @access public
	 * @param array $options
	 *		- width,
	 *		- height: The width and height to resize the image to
	 *		- quality: The quality of the image
	 *		- append: What should be appended to the end of the filename (defaults to dimensions if not set)
	 *		- prepend: What should be prepended to the front of the filename
	 *		- expand: Should the image be resized if the dimension is greater than the original dimension
	 * @param boolean $explicit
	 * @return string
	 */
	public function resize(array $options, $explicit = false) {
		if ($this->_data[$this->_current]['group'] != 'image' || !$this->_enabled) {
			return false;
		}

		$options = $options + array('width' => null, 'height' => null, 'quality' => 100, 'append' => null, 'prepend' => null, 'expand' => false, 'aspect' => true);
		$width = $this->_data[$this->_current]['width'];
		$height = $this->_data[$this->_current]['height'];
		$maxWidth = $options['width'];
		$maxHeight = $options['height'];

		if ($options['expand'] === false && (($maxWidth > $width) || ($maxHeight > $height))) {
			$newWidth = $width;
			$newHeight = $height;
		} else {
			if (is_numeric($maxWidth) && empty($maxHeight)) {
				$newWidth = $maxWidth;
				$newHeight = round(($height / $width) * $maxWidth);
				
			} else if (is_numeric($maxHeight) && empty($maxWidth)) {
				$newWidth = round(($width / $height) * $maxHeight);
				$newHeight = $maxHeight;
				
			} else if (is_numeric($maxHeight) && is_numeric($maxWidth)) {

				// Maintains the aspect ratio of the image and makes sure that it fits within the max width and max height
				if ($options['aspect']) {
					if ($maxWidth > $width) {
						$maxWidth = $width;
					}

					if ($maxHeight > $height) {
						$maxHeight = $height;
					}

					$widthScale = $maxWidth / $width;
					$heightScale = $maxHeight / $height;

					if ($widthScale < $heightScale) {
						$newWidth = $maxWidth;
						$newHeight = ($height * $newWidth) / $width;

					} elseif ($widthScale > $heightScale) {
						$newHeight = $maxHeight;
						$newWidth = ($newHeight * $width) / $height;

					} else {
						$newWidth = $maxWidth;
						$newHeight = $maxHeight;
					}
				} else {
					$newWidth = $maxWidth;
					$newHeight = $maxHeight;
				}
			} else {
				return false;
			}
		}

		$newWidth = round($newWidth);
		$newHeight = round($newHeight);
		$append = '_resized_' . $newWidth . 'x' . $newHeight;

		if ($options['append'] !== false && empty($options['append'])) {
			$options['append'] = $append;
		}

		$transform = array(
			'width'		=> $newWidth,
			'height'	=> $newHeight,
			'target'	=> $this->setDestination($this->_data[$this->_current]['name'], true, $options, false),
			'quality'	=> $options['quality']
		);

		if ($this->transform($transform)) {
			return $this->_returnData($transform, $append, $explicit);
		}

		return false;
	}

	/**
	 * Scale the image based on a percentage.
	 *
	 * @access public
	 * @param array $options
	 *		- percent: What percentage should the image be scaled to, defaults to %50 (.5)
	 *		- append: What should be appended to the end of the filename (defaults to dimensions if not set)
	 *		- prepend: What should be prepended to the front of the filename
	 *		- quality: The quality of the image
	 * @param boolean $explicit
	 * @return string
	 */
	public function scale(array $options = array(), $explicit = false) {
		if ($this->_data[$this->_current]['group'] != 'image' || !$this->_enabled) {
			return false;
		}

		$options = $options + array('percent' => .5, 'quality' => 100, 'append' => null, 'prepend' => null);
		$width = round($this->_data[$this->_current]['width'] * $options['percent']);
		$height = round($this->_data[$this->_current]['height'] * $options['percent']);

		$append = '_scaled_' . $width . 'x' . $height;

		if ($options['append'] !== false && empty($options['append'])) {
			$options['append'] = $append;
		}

		$transform = array(
			'width'		=> $width,
			'height'	=> $height,
			'target'	=> $this->setDestination($this->_data[$this->_current]['name'], true, $options, false),
			'quality'	=> $options['quality']
		);

		if ($this->transform($transform)) {
			return $this->_returnData($transform, $append, $explicit);
		}

		return false;
	}

	/**
	 * Determine the filename and path of the file.
	 *
	 * @access public
	 * @param string $name
	 * @param boolean $overwrite
	 * @param array $options
	 * @param boolean $update
	 * @return string
	 */
	public function setDestination($name = '', $overwrite = false, array $options = array(), $update = true) {
		if (isset($this->_data[$this->_current]['custom_name'])) {
			$name = $this->_data[$this->_current]['custom_name'];
		}

		$append = isset($options['append']) ? $options['append'] : '';
		$prepend = isset($options['prepend']) ? $options['prepend'] : '';
		$name = $this->formatFilename($name, $append, $prepend);
		$dest = $this->_finalDir . $name;

		if (file_exists($dest) && !$overwrite) {
			$no = 1;

			while (file_exists($this->_finalDir . $this->formatFilename($name, $append . $no, $prepend))) {
				$no++;
			}

			$name = $this->formatFilename($name, $append . $no, $prepend);
		}
		
		if ($append || $prepend) {
			$this->checkDirectory($this->uploadDir . str_replace('.', '', dirname($name)));
		}

		$dest = $this->_finalDir . basename($name);

		if ($update) {
			//$this->_data[$this->_current]['name'] = $name;
			$this->_data[$this->_current]['path'] = $dest;
		}
			
		return $dest;
	}
	
	/**
	 * Apply settings to the class.
	 * 
	 * @access public
	 * @param array $settings
	 * @return Uploader 
	 */
	public function setup(array $settings) {
		$settings = array_filter($settings);
		
		if (!empty($settings)) {
			foreach ($settings as $key => $value) {
				if ($key == 'scanFile') {
					$this->{$key} = (bool) $value;
					
				} else if ($key == 'maxNameLength') {
					$this->{$key} = (int) $value;
					
				} else if (in_array($key, array('tempDir', 'baseDir', 'uploadDir', 'ajaxField', 'maxFileSize'))) {
					$this->{$key} = (string) $value;
				}
			}
		}
		
		return $this;
	}

	/**
	 * Main function for transforming an image.
	 *
	 * @access public
	 * @param array $options
	 * @return boolean
	 */
	public function transform(array $options) {
		$options = $options + array('dest_x' => 0, 'dest_y' => 0, 'source_x' => 0, 'source_y' => 0, 'dest_w' => null, 'dest_h' => null, 'source_w' => $this->_data[$this->_current]['width'], 'source_h' => $this->_data[$this->_current]['height'], 'quality' => 100);
		$original = $this->_data[$this->_current]['path'];
		$mimeType = $this->_data[$this->_current]['type'];

		if (empty($options['dest_w'])) {
			$options['dest_w'] = $options['width'];
		}

		if (empty($options['dest_h'])) {
			$options['dest_h'] = $options['height'];
		}

		// Create an image to work with
		switch ($mimeType) {
			case 'image/gif':
				$source = imagecreatefromgif($original);
			break;
			case 'image/png':
				$source = imagecreatefrompng($original);
			break;
			case 'image/jpg':
			case 'image/jpeg':
			case 'image/pjpeg':
				$source = imagecreatefromjpeg($original);
			break;
			default:
				return false;
			break;
		}

		$target = imagecreatetruecolor($options['width'], $options['height']);

		// If gif,png allow transparencies
		if ($mimeType == 'image/gif' || $mimeType == 'image/png') {
			imagealphablending($target, false);
			imagesavealpha($target, true);
			imagefilledrectangle($target, 0, 0, $options['width'], $options['height'], imagecolorallocatealpha($target, 255, 255, 255, 127));
		}

		// Lets take our source and apply it to the temporary file and resize
		imagecopyresampled($target, $source, $options['dest_x'], $options['dest_y'], $options['source_x'], $options['source_y'], $options['dest_w'], $options['dest_h'], $options['source_w'], $options['source_h']);

		// Now write the resized image to the server
		switch ($mimeType) {
			case 'image/gif':
				imagegif($target, $options['target']);
			break;
			case 'image/png':
				imagepng($target, $options['target']);
			break;
			case 'image/jpg':
			case 'image/jpeg':
			case 'image/pjpeg':
				imagejpeg($target, $options['target'], $options['quality']);
			break;
			default:
				imagedestroy($source);
				imagedestroy($target);
				return false;
			break;
		}

		// Clear memory
		imagedestroy($source);
		imagedestroy($target);
		
		return true;
	}

	/**
	 * Upload the file to the destination.
	 *
	 * @access public
	 * @param string $file
	 * @param array $options
	 *		- name: What should the filename be changed to OR the name of a function to do the formatting
	 *		- overwrite: Should we overwrite the existant file with the same name?
	 *		- multiple: Is this method being called from uploadAll()
	 *		- append: What should be appended to the end of the filename (defaults to dimensions if not set)
	 *		- prepend: What should be prepended to the front of the filename
	 * @return mixed - Array on success, false on failure
	 */
	public function upload($file, array $options = array()) {
		$options = $options + array('name' => null, 'overwrite' => false, 'multiple' => false, 'append' => null, 'prepend' => null);

		if (!$options['multiple']) {
			if (!$this->_enabled) {
				return false;
			} else {
				$this->checkDirectory();
			}
		}

		if (isset($this->_data[$file])) {
			$this->_current = $file;
			
			$current =& $this->_data[$this->_current];
			$current['filesize'] = self::bytes($current['size']);
			$current['ext'] = self::ext($current['name']);
		} else {
			return false;
		}

		// Validate everything
		if ($this->_validates()) {
			if ($current['group'] == 'image' && !isset($current['stream'])) {
				$dimensions = $this->dimensions($current['tmp_name']);

				$current['width'] = $dimensions['width'];
				$current['height'] = $dimensions['height'];
			}
		} else {
			return false;
		}

		// Upload! Try both functions, one should work!
		$dest = $this->setDestination($options['name'], $options['overwrite'], $options);

		// Uploaded via stream / AJAX
		if (isset($current['stream'])) {
			$target = fopen($dest, 'w');        
			fseek($current['tmp_name'], 0, SEEK_SET);
			
			if (stream_copy_to_stream($current['tmp_name'], $target)) {
				$current['uploaded'] = date('Y-m-d H:i:s');
			}
			
			fclose($target);
		
		// Uploaded via POST
		} else {
			if (move_uploaded_file($current['tmp_name'], $dest)) {
				$current['uploaded'] = date('Y-m-d H:i:s');

			} else if (copy($current['tmp_name'], $dest)) {
				$current['uploaded'] = date('Y-m-d H:i:s');

			} else {
				return false;
			}
		}

		chmod($dest, 0777);
		
		return $this->_returnData();
	}

	/**
	 * Upload multiple files, but have less configuration options and no transforming.
	 *
	 * @access public
	 * @param array $fields
	 * @param boolean $overwrite
	 * @param boolean $rollback
	 * @return array
	 */
	public function uploadAll(array $fields = array(), $overwrite = false, $rollback = true) {
		if (!$this->_enabled) {
			return false;
		} else {
			$this->checkDirectory();
		}

		if (empty($fields) || !$fields) {
			$fields = array_keys($this->_data);
		}

		$data = array();
		$fail = false;

		if (!empty($fields)) {
			foreach ($fields as $field) {
				if (isset($this->_data[$field])) {
					$upload = $this->upload($field, array('overwrite' => $overwrite, 'multiple' => true));

					if (!empty($upload)) {
						$data[$field] = $upload;
					} else {
						$fail = true;
						break;
					}
				}
			}
		}

		if ($fail) {
			if ($rollback && !empty($data)) {
				foreach ($data as $file) {
					$this->delete($file['path']);
				}
			}

			return false;
		}

		return $data;
	}

	/**
	 * Attempt to load a missing extension.
	 *
	 * @access protected
	 * @param string $name
	 * @return boolean
	 */
	protected function _loadExtension($name) {
		if (!extension_loaded($name) && function_exists('dl')) {
			@dl((PHP_SHLIB_SUFFIX == 'dll' ? 'php_' : '') . $name . '.' . PHP_SHLIB_SUFFIX);
		}

		return extension_loaded($name);
	}

	/**
	 * Parse the upload data out of $_FILES.
	 *
	 * @access protected
	 * @return void
	 */
	protected function _parseData() {
		$data = array();

		// Form uploading
		if (!empty($_FILES)) {
			
			// via CakePHP
			if (isset($_FILES['data'])) {
				foreach ($_FILES['data'] as $key => $file) {
					$count = count($file);

					foreach ($file as $model => $fields) {
						foreach ($fields as $field => $value) {
							if ($count > 1) {
								$data[$model .'.'. $field][$key] = $value;
							} else {
								$data[$field][$key] = $value;
							}
						}
					}
				}
			
			// via normal form or AJAX iframe
			} else {
				$data = $_FILES;
			}
			
		// AJAX uploading
		} else if (isset($_GET[$this->ajaxField])) {
			$name = $_GET[$this->ajaxField];
			$mime = self::mimeType($name);

			if ($mime) {
				$input = fopen("php://input", "r");
				$temp = tmpfile();

				$data[$this->ajaxField] = array(
					'name' => $name,
					'type' => $mime,
					'stream' => true,
					'tmp_name' => $temp,
					'error' => 0,
					'size' => stream_copy_to_stream($input, $temp)
				);
				
				fclose($input);
			}
		}
		
		$this->_data = $data;
	}

	/**
	 * Formates and returns the data array.
	 *
	 * @access protected
	 * @param mixed $data
	 * @param string $append
	 * @param boolean $explicit
	 * @return array
	 */
	protected function _returnData($data = '', $append = '', $explicit = false) {
		if (!empty($data) && !empty($append)) {
			$this->_data[$this->_current]['path_' . trim($append, '_')] = $data['target'];

			chmod($data['target'], 0777);
			$path = str_replace($this->baseDir, '/', $data['target']);

			if ($explicit) {
				return array(
					'path' => $path,
					'width' => $data['width'],
					'height' => $data['height']
				);
			} else {
				return $path;
			}

		} else {
			$data = $this->_data[$this->_current];
			unset($data['tmp_name'], $data['error']);

			foreach ($data as $key => $value) {
				if (strpos($key, 'path') !== false) {
					$data[$key] = str_replace($this->baseDir, '/', $data[$key]);
				}
			}

			return $data;
		}
	}

	/**
	 * Does validation on the current upload.
	 *
	 * @access protected
	 * @param boolean $import
	 * @return boolean
	 */
	protected function _validates($import = false) {
		$current = $this->_data[$this->_current];
		$grouping = self::checkMimeType($current['ext'], $current['type']);

		if ($grouping) {
			$this->_data[$this->_current]['group'] = $grouping;
			
		} else if (!$import) {
			return false;
		}

		// Only validate uploaded files, not imported
		if (!$import && !isset($current['stream'])) {
			if (($current['error'] > 0) || !is_uploaded_file($current['tmp_name']) || !is_file($current['tmp_name'])) {
				return false;
			}

			// Requires the ClamAV module to be installed
			if ($this->scanFile && $this->_loadExtension('clamav')) {
				cl_setlimits(5, 1000, 200, 0, 10485760);

				if (cl_scanfile($current['tmp_name'])) {
					return false;
				}
			}
		}

		return true;
	}

}