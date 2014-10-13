<?php namespace Maddhatter\PrettyQr;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Maddhatter\PrettyQr\Exceptions\SaveQrException;
use Maddhatter\PrettyQr\Fonts\FontInterface;
use PHPQRCode\QRcode;

define('QR_MOD_SIZE', 10); //mod size in pixels
define('QR_BORDER_SIZE', 4); //border size in modules
define('QR_ERROR_CORRECTION', 'L'); //correction level

class Generator
{
    /**
     * The content of the QR code
     * @var string
     */
    private $text;

    /**
     * A 2D array of 0/1's representing the QR code
     * @var array
     */
    private $qrText;

    /**
     * The width/height of the QR code in modules
     * @var int
     */
    private $qrSize;

    /**
     * The RGBA foreground color
     * @var array
     */
    private $fg = [0, 0, 0, 0];

    /**
     * The RGBA background color
     * @var array
     */
    private $bg = [255, 255, 255, 0];

    /**
     * The angle to rotate the image
     * @var int
     */
    private $rotateAngle = 0; //how far to rotate the image to the right

    private $hideMask; //array of 1's and 0's representing what squares to hide

    private $fText; //text that appears in the center of the image

    private $fSize; //font size

    private $fFile; //font file

    /**
     * @var Response
     */
    private $response;

    /**
     * If the QR code should be solid (no gaps between modules)
     * @var bool
     */
    private $solid;

    /**
     * Create new instance
     *
     * @param Response $response
     */
    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    /**
     * Get the image response
     *
     * @return Response
     */
    public function show()
    {
        $image = $this->make();

        ob_start();
        imagepng($image);
        $contents = ob_get_contents();
        ob_end_clean();

        imagedestroy($image);

        return $this->response->create($contents, 200, ['Content-Type' => 'image/png']);
    }

    /**
     * Saves the QR code to disk
     *
     * @param $filepath
     */
    public function save($filepath)
    {
        $image = $this->make();

        $result = imagepng($image, $filepath, $this->pngQuality());

        if ( ! $result) {
            throw new SaveQrException("Unable to save QR code to: [{$filepath}]");
        }

        imagedestroy($image);
    }

    protected function pngQuality()
    {
        return 6;
    }

    /**
     * Set foreground color
     *
     * @param int $r
     * @param int $g
     * @param int $b
     * @param int $a
     * @return $this
     */
    public function foreground($r, $g, $b, $a = 0)
    {
        $this->validateRGBA($r, $g, $b, $a);
        $this->fg = [$r, $g, $b, $a];

        return $this;
    }


    /**
     * Set background color
     *
     * @param int $r
     * @param int $g
     * @param int $b
     * @param int $a
     * @return $this
     */
    public function background($r, $g, $b, $a = 0)
    {
        $this->validateRGBA($r, $g, $b, $a);
        $this->bg = [$r, $g, $b, $a];

        return $this;
    }

    /**
     * Allows user to define custom hide mask
     *
     * @param array $mask must be the same size as the current QR code
     * @return $this
     */
    public function setHideMask(array $mask)
    {
        $this->validateHideMask($mask);
        $this->hideMask = $mask;

        return $this;
    }

    /**
     * Validate the hide mask
     * @param array $mask
     */
    protected function validateHideMask(array $mask)
    {
        if (count($mask) != $this->qrSize()) {
            throw new \InvalidArgumentException("Mask size match size of QR code. Expected: {$this->qrSize()}, Actual: " . count($mask));
        }
    }

    /**
     * Set the content to be encoded in the QR code
     *
     * @param string $text
     * @return $this
     */
    public function content($text)
    {
        $this->text   = (string)$text;
        $this->qrText = QRcode::text($text, false, $this->correctionLevel(), 1, 0);
        $this->qrSize = count($this->qrText);

        return $this;
    }

    /**
     * Sets the rotation angle of the QR code
     * @param int $angle must be a multiple of 90
     * @return $this
     */
    public function rotate($angle)
    {
        $this->validateAngle($angle);
        $this->rotateAngle = $angle;

        return $this;
    }

    /**
     * Validates the rotation angle
     *
     * @param int $angle
     * @throws \InvalidArgumentException
     */
    protected function validateAngle($angle)
    {
        if ( ! is_numeric($angle)) {
            throw new \InvalidArgumentException('The angle must be numeric, actual type: [' . gettype($angle) . ']');
        }

        if ($angle % 90) {
            throw new \InvalidArgumentException("The angle must be a multiple of 90: [{$angle}]");
        }
    }

    /**
     * Returns the size of the QR code in modules
     *
     * @return int
     */
    public function qrSize()
    {
        return $this->qrSize;
    }

    /**
     * Get the size of each module in pixels
     *
     * @return int
     */
    protected function modSize()
    {
        return QR_MOD_SIZE;
    }

    /**
     * Get the size of the border in modules
     *
     * @return int
     */
    protected function borderSize()
    {
        return QR_BORDER_SIZE;
    }

    /**
     * Get a two dimensional array full of 0's the size of the QR code
     *
     * @return array
     */
    public function getEmptyMask()
    {
        return array_fill(0, $this->qrSize(), array_fill(0, $this->qrSize(), 0));
    }

    /**
     * Add text to the middle of the QR code
     * @param string $text the text to be added
     * @param FontInterface $font
     * @param int $size the font size in points
     * @return $this
     */
    public function text($text, FontInterface $font, $size)
    {
        $this->fText = $text;
        $this->fFile = $font->getFilepath();
        $this->fSize = $size;

        return $this;
    }

    /**
     * Create the image
     *
     * @return resource
     */
    public function make()
    {
        if (isset($this->fText)) {
            $fontLayer = $this->makeFontImage();

            $fontLayerInfo         = new \stdClass();
            $fontLayerInfo->width  = imagesx($fontLayer);
            $fontLayerInfo->height = imagesy($fontLayer);

            $this->hideImageArea($fontLayer);
        }

        $qrLayer = $this->makeQrImage();

        $qrSize = imagesx($qrLayer) + ($this->modSize() * $this->borderSize() * 2);

        if (isset($fontLayerInfo)) {
            $width  = max($fontLayerInfo->width, $qrSize);
            $height = max($fontLayerInfo->height, $qrSize);
        } else {
            $width = $height = $qrSize;
        }

        $base = imagecreate($width, $height);
        imagecolorallocatealpha($base, $this->bg[0], $this->bg[1], $this->bg[2], $this->bg[3]);

        $this->addLayer($qrLayer, $base);

        if (isset($fontLayer)) {
            $this->addLayer($fontLayer, $base);
        }

        //clean up image resources
        if (isset($qrLayer)) {
            imagedestroy($qrLayer);
        }

        if (isset($fontLayer)) {
            imagedestroy($fontLayer);
        }

        return $base;
    }

    /**
     * Create the QR image (no borders)
     *
     * @return resource
     */
    protected function makeQrImage()
    {
        $size    = $this->qrSize() * $this->modSize();
        $image   = imagecreate($size, $size);
        $bgColor = imagecolorallocatealpha($image, $this->bg[0], $this->bg[1], $this->bg[2], $this->bg[3]);
        $fgColor = imagecolorallocatealpha($image, $this->fg[0], $this->fg[1], $this->fg[2], $this->fg[3]);

        //base QR image
        $qrMask = $this->rotateMask($this->qrText);
        $this->applyMask($image, $qrMask, $fgColor, $this->solid);

        //solid position squares
        $posMask = $this->rotateMask($this->createPosMask());
        $this->applyMask($image, $posMask, $fgColor);

        if (isset($this->hideMask)) {
            $this->applyMask($image, $this->hideMask, $bgColor);
        }

        return $image;
    }

    /**
     * Create the font image
     *
     * @return resource
     */
    protected function makeFontImage()
    {
        $textbox = $this->calculateTextBox();
        $image   = imagecreate($textbox['width'], $textbox['height']);

        $bgColor = imagecolorallocatealpha($image, $this->bg[0], $this->bg[1], $this->bg[2], $this->bg[3]);
        $fgColor = imagecolorallocatealpha($image, $this->fg[0], $this->fg[1], $this->fg[2], $this->fg[3]);

        imagettftext($image, $this->fSize, 0, $textbox['left'], $textbox['top'], $fgColor, $this->fFile, $this->fText);

        return $image;
    }

    /**
     * Make a solid QR code with no gaps between modules
     *
     * @param bool $value
     * @return $this
     */
    public function solid($value = true)
    {
        $this->solid = $value;

        return $this;
    }

    /**
     * Add a layer to an image
     *
     * @param resource $layer
     * @param resource $base
     */
    protected function addLayer($layer, $base)
    {
        $bW = imagesx($base);
        $bH = imagesy($base);
        $lW = imagesx($layer);
        $lH = imagesy($layer);

        $x = floor(($bW - $lW) / 2);
        $y = floor(($bH - $lH) / 2);
        imagecopy($base, $layer, $x, $y, 0, 0, $lW, $lH);
    }

    protected function rotateMask($input)
    {
        $output = [];
        $last   = $this->qrSize() - 1; //last array slot ($size-1, keeps from rewriting)
        for ($k = 0; $k < ($this->rotationCount()); $k++) {
            for ($i = 0; $i < $this->qrSize(); $i++) {
                for ($j = 0; $j < $this->qrSize(); $j++) {
                    $output[$i][$j] = $input[$last - $j][$i];
                }
            }
            $input = $output;
        }

        return $output;
    }

    /**
     * Draw a QR mask onto the image
     *
     * @param resource $image
     * @param array $mask
     * @param resource $color
     * @param bool $solid
     */
    protected function applyMask($image, $mask, $color, $solid = true)
    {
        for ($i = 0; $i < $this->qrSize(); $i++) {
            for ($j = 0; $j < $this->qrSize(); $j++) {
                if ($mask[$i][$j]) {
                    if ( ! $solid) {
                        $x1 = ($j * $this->modSize()) + 1;
                        $x2 = $x1 + ($this->modSize() - 2);
                        $y1 = ($i * $this->modSize()) + 1;
                        $y2 = $y1 + ($this->modSize() - 2);
                    } else {
                        $x1 = ($j * $this->modSize());
                        $x2 = $x1 + ($this->modSize() - 1);
                        $y1 = ($i * $this->modSize());
                        $y2 = $y1 + ($this->modSize() - 1);
                    }
                    imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
                }
            }
        }
    }

    protected function hideImageArea($image)
    {
        $mask = $this->getEmptyMask();

        //determine the amount of modules to hide, but not larger than the entire QR maskSize
        $maskSize         = new \stdClass();
        $maskSize->width  = ceil(imagesx($image) / $this->modSize()) + 1;
        $maskSize->height = ceil(imagesy($image) / $this->modSize()) + 1;

        $hiddenArea = array_fill(0, $maskSize->height, array_fill(0, $maskSize->width, 1));

        $center   = floor($this->qrSize() / 2);
        $firstRow = max(0, $center - (floor($maskSize->height / 2)));
        $firstCol = max(0, $center - (floor($maskSize->width / 2)));

        foreach ($hiddenArea as $rowNum => $row) {
            array_splice($mask[$firstRow + $rowNum], $firstCol, $maskSize->width, $row);
        }

        $this->hideMask = $mask;
    }

    /**
     * Creates a mask for the position and alignment squares
     *
     * @return array
     */
    protected function createPosMask()
    {
        $mask = $this->getEmptyMask();

        $this->addSquare($mask, 0, 0, 'P');
        $this->addSquare($mask, $this->qrSize() - 7, 0, 'P');
        $this->addSquare($mask, 0, $this->qrSize() - 7, 'P');
        $this->addSquare($mask, $this->qrSize() - 9, $this->qrSize() - 9, 'A');

        return $mask;
    }

    /**
     * Adds a position or alignment square to mask array
     *
     * @param array $mask
     * @param int $x
     * @param int $y
     * @param string $type
     */
    protected function addSquare(array &$mask, $x, $y, $type)
    {
        $pSquare = [
            [1, 1, 1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 1, 1, 1, 1, 1, 1]
        ];

        $aSquare = [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1]
        ];

        if ($type == 'P') {
            for ($i = 0; $i < 7; $i++) {
                for ($j = 0; $j < 7; $j++) {
                    $mask[$x + $j][$y + $i] = $pSquare[$i][$j];

                }
            }
        }
        if ($type == 'A') {
            for ($i = 0; $i < 5; $i++) {
                for ($j = 0; $j < 5; $j++) {
                    $mask[$x + $j][$y + $i] = $aSquare[$i][$j];
                }
            }
        }
    }

    /**
     * Validate RGBA values
     *
     * @param int $r
     * @param int $g
     * @param int $b
     * @param int $a
     * @throws \InvalidArgumentException
     */
    protected function validateRGBA($r, $g, $b, $a)
    {
        $rgba = new Collection([$r, $g, $b, $a]);
        foreach ($rgba as $key => $value) {
            if ( ! is_numeric($value)) {
                throw new \InvalidArgumentException("All RGBA values must be numeric: {$rgba}");
            }
            if ($key < 3) {
                if ($value < 0 || $value > 255) {
                    throw new \InvalidArgumentException('Each RGB value must be between 0-255: ' . $rgba->slice(0, 3));
                }
            } else {
                if ($value < 0 || $value > 127) {
                    throw new \InvalidArgumentException("Alpha value must be between 0-127: [{$rgba[3]}]");
                }
            }
        }
    }

    /**
     * Get the error correction level
     *
     * @return string
     */
    protected function correctionLevel()
    {
        return QR_ERROR_CORRECTION;
    }

    protected function calculateTextBox()
    {
        /************
         * simple function that calculates the *exact* bounding box (single pixel precision).
         * The function returns an associative array with these keys:
         * left, top:  coordinates you will pass to imagettftext
         * width, height: dimension of the image you have to create
         *************/
        $rect = imagettfbbox($this->fSize, 0, $this->fFile, $this->fText);
        $minX = min(array($rect[0], $rect[2], $rect[4], $rect[6]));
        $maxX = max(array($rect[0], $rect[2], $rect[4], $rect[6]));
        $minY = min(array($rect[1], $rect[3], $rect[5], $rect[7]));
        $maxY = max(array($rect[1], $rect[3], $rect[5], $rect[7]));

        return array(
            "left" => abs($minX) - 1,
            "top" => abs($minY) - 1,
            "width" => $maxX - $minX,
            "height" => $maxY - $minY,
            "box" => $rect
        );
    }

    /**
     * @return float
     */
    protected function rotationCount()
    {
        $rotations = ($this->rotateAngle / 90) % 4;
        if ($rotations < 0) {
            $rotations = 4 + $rotations;
        }

        return $rotations;
    }
}