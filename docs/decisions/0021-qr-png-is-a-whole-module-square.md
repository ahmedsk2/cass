# The QR PNG is the smallest whole-module square at or above 1024 px

- **Context.** Spec section 5.7 asks for "SVG and PNG downloads (1024 px)". chillerlan renders whole modules only, and resampling to exactly 1024 px would blur them.
- **Decision.** Decided by the owner, recorded on 2026-09-11 in Plan 2 (`9841e14`: "Deviation, decided by the owner") and in the backlog (`d50c826`) as "Decided (owner)", with no date of its own: the PNG is the smallest whole-module square at or above 1024 px rather than exactly 1024 px, and the SVG is the print master.
- **Consequence.** The PNG is typically 1025 to 1100 px wide (Plan 2). Revisit only if a print shop asks for an exact pixel size (backlog).
- **To reverse.** `App\Actions\Conferences\GenerateConferenceQr::png()` computes the scale as `max(1, (int) ceil($minSize / $matrix->getSize()))` (`app/Actions/Conferences/GenerateConferenceQr.php`, its docblock at :47); resample the rendered PNG to exactly `$minSize`, and invert `it('renders a png of at least the configured size')` in `tests/Unit/ConferenceQrTest.php` to assert the exact size.
