/**
 * OG Image Generator for Ferguson Livestock
 * Uses Satori to convert JSX to SVG, then converts to JPEG
 * 
 * Run with: bun scripts/generate-og-image.mjs
 */

import satori from 'satori';
import { Resvg } from '@resvg/resvg-js';
import sharp from 'sharp';
import { readFile, writeFile } from 'fs/promises';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, '..');

// OG Image dimensions (standard)
const WIDTH = 1200;
const HEIGHT = 630;

// Brand colors matching the site
const colors = {
    forest: '#2d3b2d',
    sage: '#5a7247',
    mint: '#7fd4b5',
    cream: '#faf9f6',
    warm: '#8b6914',
};

async function generateOGImage() {
    console.log('🖼️  Generating OG image...');

    // Load the background image and convert to base64
    const backgroundImagePath = join(rootDir, 'src/assets/images/cows-1.webp');
    const backgroundBuffer = await readFile(backgroundImagePath);

    // Convert webp to png for better compatibility with Satori
    const pngBuffer = await sharp(backgroundBuffer)
        .resize(WIDTH, HEIGHT, { fit: 'cover', position: 'center' })
        .png()
        .toBuffer();

    const backgroundBase64 = `data:image/png;base64,${pngBuffer.toString('base64')}`;

    // Load fonts - using system fonts available on most systems
    // We'll use Google Fonts that are commonly available
    const fontRegularPath = join(rootDir, 'scripts/fonts/SourceSans3-Regular.ttf');
    const fontBoldPath = join(rootDir, 'scripts/fonts/SourceSans3-Bold.ttf');
    const fontDisplayPath = join(rootDir, 'scripts/fonts/CormorantGaramond-SemiBold.ttf');

    let fonts = [];

    try {
        const [fontRegular, fontBold, fontDisplay] = await Promise.all([
            readFile(fontRegularPath),
            readFile(fontBoldPath),
            readFile(fontDisplayPath),
        ]);

        fonts = [
            { name: 'Source Sans 3', data: fontRegular, weight: 400, style: 'normal' },
            { name: 'Source Sans 3', data: fontBold, weight: 700, style: 'normal' },
            { name: 'Cormorant Garamond', data: fontDisplay, weight: 600, style: 'normal' },
        ];
    } catch (e) {
        console.log('⚠️  Custom fonts not found, downloading...');
        // Download fonts if not present
        await downloadFonts();

        const [fontRegular, fontBold, fontDisplay] = await Promise.all([
            readFile(fontRegularPath),
            readFile(fontBoldPath),
            readFile(fontDisplayPath),
        ]);

        fonts = [
            { name: 'Source Sans 3', data: fontRegular, weight: 400, style: 'normal' },
            { name: 'Source Sans 3', data: fontBold, weight: 700, style: 'normal' },
            { name: 'Cormorant Garamond', data: fontDisplay, weight: 600, style: 'normal' },
        ];
    }

    // Create the OG image using Satori
    const svg = await satori(
        {
            type: 'div',
            props: {
                style: {
                    width: '100%',
                    height: '100%',
                    display: 'flex',
                    flexDirection: 'column',
                    position: 'relative',
                },
                children: [
                    // Background image
                    {
                        type: 'img',
                        props: {
                            src: backgroundBase64,
                            style: {
                                position: 'absolute',
                                top: 0,
                                left: 0,
                                width: '100%',
                                height: '100%',
                                objectFit: 'cover',
                            },
                        },
                    },
                    // Gradient overlay
                    {
                        type: 'div',
                        props: {
                            style: {
                                position: 'absolute',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                background: 'linear-gradient(135deg, rgba(45, 59, 45, 0.85) 0%, rgba(90, 114, 71, 0.75) 50%, rgba(45, 59, 45, 0.85) 100%)',
                            },
                        },
                    },
                    // Content container
                    {
                        type: 'div',
                        props: {
                            style: {
                                position: 'relative',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'center',
                                alignItems: 'flex-start',
                                height: '100%',
                                padding: '60px 80px',
                            },
                            children: [
                                // Badge
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: '10px',
                                            backgroundColor: 'rgba(127, 212, 181, 0.2)',
                                            border: '2px solid rgba(127, 212, 181, 0.4)',
                                            padding: '12px 24px',
                                            borderRadius: '50px',
                                            marginBottom: '28px',
                                        },
                                        children: [
                                            {
                                                type: 'span',
                                                props: {
                                                    style: {
                                                        color: colors.mint,
                                                        fontSize: '22px',
                                                        fontFamily: 'Source Sans 3',
                                                        fontWeight: 600,
                                                        letterSpacing: '0.05em',
                                                    },
                                                    children: '★ PASTURE-RAISED MURRAY GREY BEEF',
                                                },
                                            },
                                        ],
                                    },
                                },
                                // Main heading
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            fontFamily: 'Cormorant Garamond',
                                            fontSize: '96px',
                                            fontWeight: 600,
                                            color: colors.cream,
                                            lineHeight: 1.05,
                                            marginBottom: '24px',
                                            maxWidth: '950px',
                                        },
                                        children: 'Ferguson Livestock',
                                    },
                                },
                                // Subheading
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            fontFamily: 'Source Sans 3',
                                            fontSize: '36px',
                                            fontWeight: 400,
                                            color: 'rgba(250, 249, 246, 0.9)',
                                            lineHeight: 1.35,
                                            maxWidth: '800px',
                                            marginBottom: '40px',
                                        },
                                        children: 'Premium beef boxes delivered direct from our family farm in Snake Valley, Victoria',
                                    },
                                },
                                // Features row
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            display: 'flex',
                                            gap: '40px',
                                        },
                                        children: [
                                            {
                                                type: 'div',
                                                props: {
                                                    style: {
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        gap: '10px',
                                                    },
                                                    children: [
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.mint,
                                                                    fontSize: '28px',
                                                                },
                                                                children: '✓',
                                                            },
                                                        },
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.cream,
                                                                    fontFamily: 'Source Sans 3',
                                                                    fontSize: '24px',
                                                                    fontWeight: 400,
                                                                },
                                                                children: 'Pasture-Raised',
                                                            },
                                                        },
                                                    ],
                                                },
                                            },
                                            {
                                                type: 'div',
                                                props: {
                                                    style: {
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        gap: '10px',
                                                    },
                                                    children: [
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.mint,
                                                                    fontSize: '28px',
                                                                },
                                                                children: '✓',
                                                            },
                                                        },
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.cream,
                                                                    fontFamily: 'Source Sans 3',
                                                                    fontSize: '24px',
                                                                    fontWeight: 400,
                                                                },
                                                                children: 'No Hormones',
                                                            },
                                                        },
                                                    ],
                                                },
                                            },
                                            {
                                                type: 'div',
                                                props: {
                                                    style: {
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        gap: '10px',
                                                    },
                                                    children: [
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.mint,
                                                                    fontSize: '28px',
                                                                },
                                                                children: '✓',
                                                            },
                                                        },
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.cream,
                                                                    fontFamily: 'Source Sans 3',
                                                                    fontSize: '24px',
                                                                    fontWeight: 400,
                                                                },
                                                                children: 'Family Farm',
                                                            },
                                                        },
                                                    ],
                                                },
                                            },
                                            {
                                                type: 'div',
                                                props: {
                                                    style: {
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        gap: '10px',
                                                    },
                                                    children: [
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.mint,
                                                                    fontSize: '28px',
                                                                },
                                                                children: '✓',
                                                            },
                                                        },
                                                        {
                                                            type: 'span',
                                                            props: {
                                                                style: {
                                                                    color: colors.cream,
                                                                    fontFamily: 'Source Sans 3',
                                                                    fontSize: '24px',
                                                                    fontWeight: 400,
                                                                },
                                                                children: 'Free Delivery',
                                                            },
                                                        },
                                                    ],
                                                },
                                            },
                                        ],
                                    },
                                },
                            ],
                        },
                    },
                    // Website URL at bottom
                    {
                        type: 'div',
                        props: {
                            style: {
                                position: 'absolute',
                                bottom: '36px',
                                right: '70px',
                                display: 'flex',
                                alignItems: 'center',
                                gap: '12px',
                            },
                            children: [
                                {
                                    type: 'span',
                                    props: {
                                        style: {
                                            color: 'rgba(250, 249, 246, 0.8)',
                                            fontFamily: 'Source Sans 3',
                                            fontSize: '24px',
                                            fontWeight: 400,
                                        },
                                        children: 'fergusonlivestock.com.au',
                                    },
                                },
                            ],
                        },
                    },
                ],
            },
        },
        {
            width: WIDTH,
            height: HEIGHT,
            fonts,
        }
    );

    console.log('✅ SVG generated');

    // Convert SVG to PNG using resvg
    const resvg = new Resvg(svg, {
        fitTo: {
            mode: 'width',
            value: WIDTH,
        },
    });

    const pngData = resvg.render();
    const pngOutputBuffer = pngData.asPng();

    console.log('✅ PNG rendered');

    // Convert PNG to JPEG using sharp
    const jpegBuffer = await sharp(pngOutputBuffer)
        .jpeg({ quality: 90 })
        .toBuffer();

    // Save to public folder
    const outputPath = join(rootDir, 'public/og-image.jpg');
    await writeFile(outputPath, jpegBuffer);

    console.log(`✅ OG image saved to: ${outputPath}`);
    console.log(`📐 Dimensions: ${WIDTH}x${HEIGHT}px`);
}

async function downloadFonts() {
    const { mkdir } = await import('fs/promises');
    const fontsDir = join(rootDir, 'scripts/fonts');

    try {
        await mkdir(fontsDir, { recursive: true });
    } catch (e) {
        // Directory exists
    }

    // Use Google Fonts API to get font URLs
    // Inter is a reliable fallback font available via Google Fonts
    const fonts = [
        {
            // Source Sans 3 Regular from fontsource CDN
            url: 'https://cdn.jsdelivr.net/fontsource/fonts/source-sans-3@latest/latin-400-normal.ttf',
            filename: 'SourceSans3-Regular.ttf',
        },
        {
            // Source Sans 3 Bold from fontsource CDN
            url: 'https://cdn.jsdelivr.net/fontsource/fonts/source-sans-3@latest/latin-700-normal.ttf',
            filename: 'SourceSans3-Bold.ttf',
        },
        {
            // Cormorant Garamond from fontsource CDN
            url: 'https://cdn.jsdelivr.net/fontsource/fonts/cormorant-garamond@latest/latin-600-normal.ttf',
            filename: 'CormorantGaramond-SemiBold.ttf',
        },
    ];

    for (const font of fonts) {
        console.log(`📥 Downloading ${font.filename}...`);
        const response = await fetch(font.url);
        if (!response.ok) {
            throw new Error(`Failed to download ${font.filename}: ${response.status}`);
        }
        const buffer = await response.arrayBuffer();
        await writeFile(join(fontsDir, font.filename), Buffer.from(buffer));
    }

    console.log('✅ Fonts downloaded');
}

// Run the generator
generateOGImage().catch(console.error);

