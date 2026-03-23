<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Loader
 *
 * Loads PHP files from a modules directory and calls ::register() on
 * classes named SD_Module_*.
 */
final class SD_Module_Loader {

  public static function load_modules_dir(string $dir) : void {
    if (!is_dir($dir)) return;

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($autoload)) {
      require_once $autoload;
    }

    $before = get_declared_classes();

    $files = self::php_files_recursive($dir);

// 🔒 Filter out junk / duplicates / backups
$files = array_filter($files, static function($file) {
  $file = str_replace('\\', '/', $file);

  // skip hidden / backup / nested junk
  if (strpos($file, '/.') !== false) return false;
  if (strpos($file, '/backup') !== false) return false;
  if (strpos($file, '/old') !== false) return false;
  if (strpos($file, '/copy') !== false) return false;
  if (strpos($file, '/tmp') !== false) return false;

  return true;
});
    $files = self::sort_module_files($files);

    foreach ($files as $file) {
      require_once $file;
    }

    $after = get_declared_classes();
    $new_classes = array_diff($after, $before);

    foreach ($new_classes as $cls) {
      if (strpos($cls, 'SD_Module_') !== 0) continue;
      if (!method_exists($cls, 'register')) continue;

      try {
        $cls::register();
      } catch (\Throwable $e) {
        SD_Util::log('module_register_error', [
          'module' => $cls,
          'error'  => $e->getMessage(),
        ]);
      }
    }
  }

  private static function php_files_recursive(string $dir) : array {
    $files = [];

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if (!$file->isFile()) continue;
      if (strtolower($file->getExtension()) !== 'php') continue;
      $files[] = $file->getPathname();
    }

    return $files;
  }

  private static function sort_module_files(array $files) : array {
    usort($files, static function (string $a, string $b) : int {
      $wa = self::module_file_weight($a);
      $wb = self::module_file_weight($b);

      if ($wa !== $wb) {
        return $wa <=> $wb;
      }

      return strcmp($a, $b);
    });

    return $files;
  }

  private static function module_file_weight(string $file) : int {
    $name = basename($file);

    if (substr($name, -13) === 'Interface.php') return 10;
    if (substr($name, -9) === 'Trait.php') return 20;
    if (strpos($name, 'Abstract') === 0) return 30;
    if (strpos($name, 'Base') === 0) return 40;

    if (substr($name, -10) === 'config.php') return 900;
    if (substr($name, -14) === '-bootstrap.php') return 1000;
    if (substr($name, -9) === 'entry.php') return 1100;

    return 100;
  }
}