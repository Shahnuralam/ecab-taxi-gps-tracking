=== E-cab Taxi GPS Tracking ===
Contributors: magepeopleteam
Tags: taxi, gps, live tracking, pwa, driver
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-based browser and PWA live GPS tracking for E-cab Taxi Booking Manager PRO.

== Description ==

This add-on lets an assigned driver share GPS coordinates from a secure browser
page or installed PWA. Customers receive a token-protected live tracking view,
and transportation managers can monitor bookings and control accuracy, update
frequency, minimum movement, stale-location thresholds and retention.

Pickup and destination coordinates come from each booking's verified map search.
The live map moves the driver marker, retains a short recent trail, shows pickup
and destination, calculates remaining route distance/ETA, and automatically
changes from "to pickup" to "to destination" when the driver reaches the
configured arrival radius.

Drivers can publish an online/offline availability state. The driver panel also
shows current network connectivity, GPS accuracy and battery level when the
browser exposes those APIs. A previously started trip is remembered and resumes
after the page or installed PWA is reopened when Location permission remains
granted. Explicitly pressing Stop or going offline clears the remembered trip.

Customers can opt in to browser notifications when the driver reaches pickup
and destination. Notification permission always belongs to the browser. These
alerts are generated while the protected tracking page/PWA is actively polling;
they are not remote push notifications after the browser has fully closed.

Browsers always control Location permission. WordPress and site administrators
cannot silently approve it. A driver must grant permission after pressing the
Start button, and the page must remain running for continuous browser tracking.
Mobile operating systems may suspend a browser/PWA in the background or while
the screen is locked; a browser-only solution cannot guarantee background GPS.

== Requirements ==

* E-cab Taxi Booking Manager
* E-cab Taxi Booking Manager PRO
* HTTPS (required by browser geolocation and service workers)
* An assigned PRO driver and booking

Browsers treat localhost as a secure development exception. A real hostname or
LAN address must use HTTPS for GPS and PWA installation.

== Installation ==

1. Activate both E-cab parent plugins.
2. Activate E-cab Taxi GPS Tracking.
3. Open Transportation > Live GPS and review the defaults.
4. Give drivers the generated Driver Live Tracking page or the Live GPS item in
   their WooCommerce account. The same controls are also embedded below the
   existing PRO Driver Panel.
5. Customer links use the generated Track Your Taxi page and the booking's
   private access token.

Customer tracking buttons are added to supported booking confirmations,
WooCommerce order details and the PRO My Bookings detail view. Dispatch can
also open the protected customer link from the Live GPS monitor.

Live GPS uses the Transportation plugin's modern admin shell. Global controls
also appear as a GPS Tracking configuration area under Transportation >
Settings.

== Privacy ==

Only the assigned driver or a transportation manager can submit coordinates.
Customer reads require account ownership, manager capability, or the private
booking access token. Location history is deleted hourly after the configured
retention period. Uninstalling the add-on removes its table, location metadata,
settings and auto-created pages.

== Developer Hooks ==

Filters: mptbm_gps_can_driver_track, mptbm_gps_location_payload,
mptbm_gps_stop_statuses.

Actions: mptbm_gps_tracking_started, mptbm_gps_location_recorded,
mptbm_gps_tracking_stopped, mptbm_gps_trip_phase_changed,
mptbm_gps_driver_availability_changed.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added driver PWA, assigned-booking GPS capture and install prompt.
* Added self-healing manifest/service-worker routes and visible PWA readiness feedback.
* Added dynamic trip coordinates, moving Leaflet map, recent trail, trip phases,
  remaining route distance and ETA.
* Added private customer tracking and transportation-manager monitor.
* Added retention cleanup, rate limiting and automatic trip-stop integration.
* Added driver availability, device/GPS health, opt-in customer arrival alerts,
  and permission-aware tracking restoration after reopening the page or PWA.
