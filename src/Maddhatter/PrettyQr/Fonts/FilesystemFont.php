<?php namespace Maddhatter\PrettyQr\Fonts;

use Illuminate\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;

class FilesystemFont implements FontInterface
{

    /**
     * Path to font file
     * @var string
     */
    protected $filepath;

    /**
     * Filesystem object to validate path
     * @var Filesystem
     */
    private $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Get the absolute path to the font
     * @return string
     */
    public function getFilepath()
    {
        return $this->filepath;
    }

    /**
     * Set the path to the font
     *
     * @param string $filepath
     */
    public function setFilepath($filepath)
    {
        $this->validateFilepath($filepath);
        $this->filepath = $filepath;
    }

    /**
     * Validate filepath
     * @param string $filepath
     * @throws FileNotFoundException
     */
    private function validateFilepath($filepath)
    {
        if ( ! $this->filesystem->exists($filepath)) {
            throw new FileNotFoundException("Could not locate font file: [{$filepath}]");
        }
    }
}