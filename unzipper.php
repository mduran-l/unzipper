<?php
/**
 * The Unzipper extracts .zip or .rar archives and .gz files on webservers.
 * It's handy if you do not have shell access. E.g. if you want to upload a lot
 * of files (php framework or image collection) as an archive to save time.
 * As of version 0.1.0 it also supports creating archives.
 *
 * @author  Andreas Tasch, at[tec], attec.at
 * @license GNU GPL v3
 * @package attec.toolbox
 * @version 0.1.1g
 */
define('VERSION', '0.1.1g');

$timestart = microtime(true);

// Output file prefix
Zipper::$prefix = 'swiss-smile';

// Files to skip
Zipper::$skipFile = [
    './.user.ini',
    //'./wp-config.php',
];

// Folders to skip
Zipper::$skipDir = [
    './.quarantine',
    './.tmb',
    './.well-known',
	'./wp-content/updraft',
];

$unzipper = new Unzipper;
if (isset($_POST['dounzip'])) {
    // Check if an archive was selected for unzipping.
    $archive = isset($_POST['zipfile']) ? strip_tags($_POST['zipfile']) : '';
    $destination = isset($_POST['extpath']) ? strip_tags($_POST['extpath']) : '';
    $unzipper->prepareExtraction($archive, $destination);
}

if (isset($_POST['dozip'])) {
    // Resulting zipfile e.g. zipper-20160723.1155.zip.
    Zipper::zipDir(!empty($_POST['zippath']) ? strip_tags($_POST['zippath']) : '.', Zipper::$prefix . '-' . date('Ymd.Hi') . '.zip');
}

$timeend = microtime(TRUE);
$time = round($timeend - $timestart, 4);

/**
 * Class Unzipper
 */
class Unzipper
{

    public $localdir = '.';
    public $zipfiles = [];
    public static $status = [];

    public function __construct()
    {
        // Read directory and pick .zip, .rar and .gz files.
        if ($dh = opendir($this->localdir)) {
            while (($file = readdir($dh)) !== FALSE) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'zip' || pathinfo($file, PATHINFO_EXTENSION) === 'gz' || pathinfo($file, PATHINFO_EXTENSION) === 'rar'
                ) {
                    $this->zipfiles[] = $file;
                }
            }
            closedir($dh);

            if (!empty($this->zipfiles)) {
                Unzipper::$status = ['info' => '.zip or .gz or .rar files found, ready for extraction'];
            } else {
                Unzipper::$status = ['info' => 'No .zip or .gz or rar files found. So only zipping functionality available.'];
            }
        }
    }

    /**
     * Prepare and check zipfile for extraction.
     *
     * @param string $archive
     *   The archive name including file extension. E.g. my_archive.zip.
     * @param string $destination
     *   The relative destination path where to extract files.
     */
    public function prepareExtraction($archive, $destination = '')
    {
        // Determine paths.
        if (empty($destination)) {
            $extpath = $this->localdir;
        } else {
            $extpath = $this->localdir . '/' . $destination;
            // TODO: move this to extraction function.
            if (!is_dir($extpath)) {
                mkdir($extpath);
            }
        }
        // Only local existing archives are allowed to be extracted.
        if (in_array($archive, $this->zipfiles)) {
            self::extract($archive, $extpath);
        }
    }

    /**
     * Checks file extension and calls suitable extractor functions.
     *
     * @param string $archive
     *   The archive name including file extension. E.g. my_archive.zip.
     * @param string $destination
     *   The relative destination path where to extract files.
     */
    public static function extract($archive, $destination)
    {
        $ext = pathinfo($archive, PATHINFO_EXTENSION);
        switch ($ext) {
            case 'zip':
                self::extractZipArchive($archive, $destination);
                break;
            case 'gz':
                self::extractGzipFile($archive, $destination);
                break;
            case 'rar':
                self::extractRarArchive($archive, $destination);
                break;
        }
    }

    /**
     * Decompress/extract a zip archive using ZipArchive.
     *
     * @param $archive
     * @param $destination
     */
    public static function extractZipArchive($archive, $destination)
    {
        // Check if webserver supports unzipping.
        if (!class_exists('ZipArchive')) {
            Unzipper::$status = ['error' => 'Error: Your PHP version does not support unzip functionality.'];
            return;
        }

        $zip = new ZipArchive;

        // Check if archive is readable.
        if ($zip->open($archive) === TRUE) {
            // Check if destination is writable
            if (is_writeable($destination . '/')) {
                $zip->extractTo($destination);
                $zip->close();
                Unzipper::$status = ['success' => 'Files unzipped successfully'];
            } else {
                Unzipper::$status = ['error' => 'Error: Directory not writeable by webserver.'];
            }
        } else {
            Unzipper::$status = ['error' => 'Error: Cannot read .zip archive.'];
        }
    }

    /**
     * Decompress a .gz File.
     *
     * @param string $archive
     *   The archive name including file extension. E.g. my_archive.zip.
     * @param string $destination
     *   The relative destination path where to extract files.
     */
    public static function extractGzipFile($archive, $destination)
    {
        // Check if zlib is enabled
        if (!function_exists('gzopen')) {
            Unzipper::$status = ['error' => 'Error: Your PHP has no zlib support enabled.'];
            return;
        }

        $filename = pathinfo($archive, PATHINFO_FILENAME);
        $gzipped = gzopen($archive, 'rb');
        $file = fopen($destination . '/' . $filename, 'w');

        while ($string = gzread($gzipped, 4096)) {
            fwrite($file, $string, strlen($string));
        }
        gzclose($gzipped);
        fclose($file);

        // Check if file was extracted.
        if (file_exists($destination . '/' . $filename)) {
            Unzipper::$status = ['success' => 'File unzipped successfully.'];

            // If we had a tar.gz file, let's extract that tar file.
            if (pathinfo($destination . '/' . $filename, PATHINFO_EXTENSION) == 'tar') {
                $phar = new PharData($destination . '/' . $filename);
                if ($phar->extractTo($destination)) {
                    Unzipper::$status = ['success' => 'Extracted tar.gz archive successfully.'];
                    // Delete .tar.
                    unlink($destination . '/' . $filename);
                }
            }
        } else {
            Unzipper::$status = ['error' => 'Error unzipping file.'];
        }
    }

    /**
     * Decompress/extract a Rar archive using RarArchive.
     *
     * @param string $archive
     *   The archive name including file extension. E.g. my_archive.zip.
     * @param string $destination
     *   The relative destination path where to extract files.
     */
    public static function extractRarArchive($archive, $destination)
    {
        // Check if webserver supports unzipping.
        if (!class_exists('RarArchive')) {
            Unzipper::$status = ['error' => 'Error: Your PHP version does not support .rar archive functionality. <a class="info" href="http://php.net/manual/en/rar.installation.php" target="_blank">How to install RarArchive</a>'];
            return;
        }
        // Check if archive is readable.
        if ($rar = RarArchive::open($archive)) {
            // Check if destination is writable
            if (is_writeable($destination . '/')) {
                $entries = $rar->getEntries();
                foreach ($entries as $entry) {
                    $entry->extract($destination);
                }
                $rar->close();
                Unzipper::$status = ['success' => 'Files extracted successfully.'];
            } else {
                Unzipper::$status = ['error' => 'Error: Directory not writeable by webserver.'];
            }
        } else {
            Unzipper::$status = ['error' => 'Error: Cannot read .rar archive.'];
        }
    }

}

/**
 * Class Zipper
 *
 * Copied and slightly modified from http://at2.php.net/manual/en/class.ziparchive.php#110719
 * @author umbalaconmeogia
 */
class Zipper
{

    public static $prefix = 'zipper';
    public static $skipped = [];
    public static $skipFile = [];
    public static $skipDir = [];

    /**
     * Add files and sub-directories in a folder to zip file.
     *
     * @param string $folder
     *   Path to folder that should be zipped.
     *
     * @param ZipArchive $zipFile
     *   Zipfile where files end up.
     *
     * @param int $exclusiveLength
     *   Number of text to be exclusived from the file path.
     */
    private static function folderToZip($folder, &$zipFile, $exclusiveLength)
    {
        $handle = opendir($folder);

        while (($f = readdir($handle)) !== false) {
            // Check for local/parent path or zipping file itself and skip.
            if ($f != '.' && $f != '..' && $f != basename(__FILE__)) {
                $filePath = $folder . '/' . $f;
                // Remove prefix from file path before add to zip.
                $localPath = substr($filePath, $exclusiveLength);
                if (is_file($filePath)) {
                    $skip = false;
                    if (strpos($filePath, Zipper::$prefix) !== false || in_array($filePath, self::$skipFile) || $f == '.DS_Store') {
                        $skip = true;
                        self::$skipped[] = $filePath;
                    }
                    if (!$skip) {
                        $zipFile->addFile($filePath, $localPath);
                    }
                } elseif (is_dir($filePath)) {
                    $skip = false;
                    if (in_array($filePath, self::$skipDir)) {
                        self::$skipped[] = $filePath;
                        $skip = true;
                    }
                    if (!$skip) {
                        // Add sub-directory.
                        self::folderToZip($filePath, $zipFile, $exclusiveLength);
                        $zipFile->addEmptyDir($localPath);
                    }
                }
            }
        }
        closedir($handle);
    }

    /**
     * Zip a folder (including itself).
     *
     * Usage:
     *   Zipper::zipDir('path/to/sourceDir', 'path/to/out.zip');
     *
     * @param string $sourcePath
     *   Relative path of directory to be zipped.
     *
     * @param string $outZipPath
     *   Relative path of the resulting output zip file.
     */
    public static function zipDir($sourcePath, $outZipPath)
    {
        $pathInfo = pathinfo($sourcePath);
        $parentPath = $pathInfo['dirname'];
        $dirName = $pathInfo['basename'];

        $z = new ZipArchive();
        $z->open($outZipPath, ZipArchive::CREATE);
        if ($sourcePath != '.') {
            $z->addEmptyDir($dirName);
        }
        if ($sourcePath == $dirName) {
            self::folderToZip($sourcePath, $z, 0);
        } else {
            self::folderToZip($sourcePath, $z, strlen($parentPath . '/'));
        }
        $z->close();

        Unzipper::$status = ['success' => 'Successfully created archive ' . $outZipPath];
    }

}
?>
<!DOCTYPE html>
<html>
    <head>
        <title>File Unzipper + Zipper</title>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    </head>
    <body>
        <p class="status status--<?= strtoupper(key(Unzipper::$status)); ?>">
            Status: <?= reset(Unzipper::$status); ?><br/>
            <?php if (!empty(Zipper::$skipped)): ?>
            <p class="small">
            <h2>Skipped files/folders</h2>
            <ul>
                <?php foreach (Zipper::$skipped as $skipped): ?>
                    <li><?= $skipped ?></li>
                <?php endforeach ?>
            </ul>
        </p>
    <?php endif ?>
    <span class="small">Processing Time: <?= $time; ?> seconds</span>
</p>
<form action="" method="POST">
    <fieldset>
        <h1>Archive Unzipper</h1>
        <label for="zipfile">Select .zip or .rar archive or .gz file you want to extract:</label>
        <select name="zipfile" id="zipfile" size="1" class="select">
            <?php
            foreach ($unzipper->zipfiles as $zip) {
                echo '<option>' . $zip . '</option>';
            }
            ?>
        </select>
        <label for="extpath">Extraction path (optional):</label>
        <input type="text" name="extpath" id="extpath" class="form-field" />
        <p class="info">Enter extraction path without leading or trailing slashes (e.g. "mypath"). If left empty current directory will be used.</p>
        <button type="submit" name="dounzip" class="submit">Unzip Archive</button>
    </fieldset>

    <fieldset>
        <h1>Archive Zipper</h1>
        <label for="zippath">Path that should be zipped (optional):</label>
        <input type="text" name="zippath" id="zippath" class="form-field" />
        <p class="info">Enter path to be zipped without leading or trailing slashes (e.g. "zippath"). If left empty current directory will be used.</p>
        <button type="submit" name="dozip" class="submit">Zip Archive</button>
    </fieldset>
</form>

<p class="version">Unzipper version: <?= VERSION; ?></p>
<style type="text/css">
    <!--
    body {
        font-family: Arial, sans-serif;
        line-height: 150%;
    }

    label {
        display: block;
        margin-top: 20px;
    }

    fieldset {
        border: 0;
        background-color: #EEE;
        margin: 10px 0 10px 0;
    }

    .select {
        padding: 5px;
        font-size: 110%;
    }

    .status {
        margin: 0;
        margin-bottom: 20px;
        padding: 10px;
        font-size: 80%;
        background: #EEE;
        border: 1px dotted #DDD;
    }

    .status--ERROR {
        background-color: red;
        color: white;
        font-size: 120%;
    }

    .status--SUCCESS {
        background-color: green;
        font-weight: bold;
        color: white;
        font-size: 120%
    }

    .small {
        font-size: 0.7rem;
        font-weight: normal;
    }

    .version {
        font-size: 80%;
    }

    .form-field {
        border: 1px solid #AAA;
        padding: 8px;
        width: 280px;
    }

    .info {
        margin-top: 0;
        font-size: 80%;
        color: #777;
    }

    .submit {
        background-color: #378de5;
        border: 0;
        color: #ffffff;
        font-size: 15px;
        padding: 10px 24px;
        margin: 20px 0 20px 0;
        text-decoration: none;
    }

    .submit:hover {
        background-color: #2c6db2;
        cursor: pointer;
    }
    -->
</style>
</body>
</html>
