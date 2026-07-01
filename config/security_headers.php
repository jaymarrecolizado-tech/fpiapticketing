<?php
/**
 * Security Headers Configuration
 * 
 * Implements critical security headers to protect against:
 * - XSS attacks (Content-Security-Policy)
 * - Clickjacking attacks (X-Frame-Options)
 * - MIME type sniffing (X-Content-Type-Options)
 * - SSL stripping attacks (Strict-Transport-Security)
 * - Information leakage (Referrer-Policy)
 * - Unauthorized feature access (Permissions-Policy)
 * 
 * These headers provide defense-in-depth security without breaking existing functionality.
 * Compatible with:
 * - Bootstrap 5.3.2 (from CDN)
 * - Leaflet maps library
 * - OpenStreetMap/Nominatim APIs
 * - Inline JavaScript autocomplete functionality
 */

// Prevent MIME type sniffing (blocks browser from executing files as scripts if misnamed)
header('X-Content-Type-Options: nosniff');

// Prevent clickjacking attacks (only allow embedding in same-origin frames)
header('X-Frame-Options: SAMEORIGIN');

// Enable browser's built-in XSS protection for older browsers
header('X-XSS-Protection: 1; mode=block');

/**
 * Content-Security-Policy (CSP)
 * 
 * Restricts sources for scripts, stylesheets, images, fonts, and connections.
 * This is the primary defense against XSS and injection attacks.
 * 
 * Configuration breakdown:
 * - default-src 'self': All resources default to same-origin only
 * - script-src 'self' 'unsafe-inline': Allow inline scripts (required for autocomplete AJAX)
 * - style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net: Allow inline styles + Bootstrap CDN
 * - img-src 'self' data: https://*.tile.openstreetmap.org: Allow data URIs + OpenStreetMap tiles with subdomains
 * - font-src 'self' https://cdn.jsdelivr.net: Bootstrap fonts from CDN
 * - connect-src 'self' https://cdn.jsdelivr.net https://nominatim.openstreetmap.org https://*.tile.openstreetmap.org: For APIs, source maps, and subdomains
 * - frame-ancestors 'self': Prevent framing from external sites
 * - upgrade-insecure-requests: Convert HTTP to HTTPS (for production)
 */
$csp = "default-src 'self'; " .
       "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
       "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
       "img-src 'self' data: https://*.tile.openstreetmap.org; " .
       "font-src 'self' https://cdn.jsdelivr.net; " .
       "connect-src 'self' https://cdn.jsdelivr.net https://nominatim.openstreetmap.org https://*.tile.openstreetmap.org; " .
       "frame-ancestors 'self'; " .
       "upgrade-insecure-requests";

header("Content-Security-Policy: " . $csp);

/**
 * Strict-Transport-Security (HSTS)
 * 
 * Forces browser to use HTTPS only, preventing SSL stripping attacks.
 * Parameters:
 * - max-age=31536000: Cache HSTS policy for 1 year (31,536,000 seconds)
 * - includeSubDomains: Apply to all subdomains
 * - preload: Allow inclusion in browser HSTS preload lists
 * 
 * NOTE: Only set this if your site is fully HTTPS in production.
 * For localhost/HTTP development, this is safe but won't take effect.
 */
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!empty($_SERVER['HTTPS']) || $host !== 'localhost') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

/**
 * Referrer-Policy
 * 
 * Controls what referrer information is sent when navigating to external sites.
 * strict-origin-when-cross-origin: Send only origin (not full URL) for cross-site requests
 * This prevents URL parameters and sensitive data from leaking to external sites.
 */
header('Referrer-Policy: strict-origin-when-cross-origin');

/**
 * Permissions-Policy (formerly Feature-Policy)
 * 
 * Disables dangerous browser features to prevent unauthorized access.
 * Blocks:
 * - geolocation: Browser's location API
 * - microphone: Microphone access
 * - camera: Camera access
 * - payment: Payment request API
 * - usb: USB access
 * - accelerometer: Device motion sensor
 * - gyroscope: Device rotation sensor
 * - magnetometer: Compass sensor
 * - vr: VR headset access
 * - xr-spatial-tracking: XR tracking
 */
header("Permissions-Policy: " .
       "geolocation=(), " .
       "microphone=(), " .
       "camera=(), " .
       "payment=(), " .
       "usb=(), " .
       "accelerometer=(), " .
       "gyroscope=(), " .
       "magnetometer=(), " .
       "xr-spatial-tracking=()");

/**
 * Additional Security Headers (Optional but Recommended)
 */

// Cross-Origin-Opener-Policy: Isolate global object from cross-origin scripts
header('Cross-Origin-Opener-Policy: same-origin');

// Cross-Origin-Resource-Policy: Restrict how document can be used by other sites
header('Cross-Origin-Resource-Policy: same-origin');

// X-Permitted-Cross-Domain-Policies: Blocks Adobe Flash/PDF from loading cross-domain
header('X-Permitted-Cross-Domain-Policies: none');

// NOTE: Removed Cross-Origin-Embedder-Policy: require-corp
// This was blocking OpenStreetMap tiles which don't have CORS headers
// COEP is meant for high-security scenarios and breaks third-party map services

/**
 * DEVELOPMENT HELPERS
 * Uncomment for debugging CSP violations in browser console
 */

// Log CSP violations to server (optional - requires endpoint implementation)
// $csp_report_uri = "/api/csp-report.php";
// Additional CSP header for reporting (don't enforce, just report):
// header("Content-Security-Policy-Report-Only: " . $csp . "; report-uri " . $csp_report_uri);

// If you need to check headers in browser, use:
// F12 Console → Network tab → Click request → Response Headers
// Should see all X-* and CSP headers listed

?>
