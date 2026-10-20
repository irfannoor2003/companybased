<x-app-layout :pageTitle="'My Attendance'">
    <x-slot name="header">
        <x-page-header title="My Attendance" description="Record and review your own attendance for this month." icon="clock" />
    </x-slot>

    @include('employees._tabs')

    @if (! $employee)
        <div class="mt-6">
            <x-card>
                <x-empty-state icon="clock" title="No employee profile" description="Your account is not linked to an employee profile, so attendance cannot be recorded." />
            </x-card>
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-card title="Today" description="Status for {{ now()->format('l, M d Y') }}" x-data="attendanceScanner()">
                @php
                    $status = $today?->status ?? 'pending';
                    $action = ($today && $today->check_in_at && ! $today->check_out_at) ? 'Clock out' : 'Clock in';
                @endphp
                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Status</span>
                        <x-employees.status-badge :status="$status" />
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Check in</span>
                        <span class="font-mono text-ink">{{ $today?->check_in_at?->format('H:i') ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Check out</span>
                        <span class="font-mono text-ink">{{ $today?->check_out_at?->format('H:i') ?: '—' }}</span>
                    </div>
                    @if ($today?->work_minutes)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-ink-faint">Worked</span>
                            <span class="font-mono text-ink">{{ gmdate('H:i', $today->work_minutes * 60) }}</span>
                        </div>
                    @endif

                    @if ($onLeaveToday)
                        <div class="border-t border-line pt-4">
                            <p class="text-sm text-ink-faint text-center">You are on approved leave today — attendance is not required.</p>
                        </div>
                    @endif

                    {{-- QR Code Scanner --}}
                    @if (! $onLeaveToday && ! ($today && $today->check_in_at && $today->check_out_at))
                        <div class="border-t border-line pt-4">
                            <p class="text-xs text-ink-faint mb-2">Scan the office QR code to mark attendance</p>
                            <div id="qr-reader" class="mb-3 hidden overflow-hidden rounded-xl border border-line"></div>
                            <div class="flex flex-col gap-2">
                                <x-button type="button" icon="scan" variant="secondary" class="w-full"
                                    @click="toggleScanner()" x-text="scanning ? 'Stop camera' : 'Scan QR Code'"></x-button>
                                <label class="btn-ghost btn-sm w-full cursor-pointer justify-center">
                                    <x-icon name="upload" class="size-4" />
                                    Upload QR image
                                    <input type="file" accept="image/*" class="hidden" @change="handleQrUpload($event)" />
                                </label>
                            </div>
                        </div>
                    @endif

                    {{-- Status Messages --}}
                    {{-- Alpha backgrounds rather than bg-green-50/border-green-200: the
                         solid -50 tints are near-white, so they forced a bright panel
                         onto a dark-mode phone. Alpha over the surface token reads
                         correctly in both modes without a second set of classes. --}}
                    <template x-if="message">
                        <div :class="success ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30' : 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30'"
                            class="rounded-lg border px-3 py-2 text-sm" x-text="message"></div>
                    </template>

                    {{-- Manual Clock In/Out (with location) --}}
                    @if (auth()->user()->can('employees.my_attendance.mark') && ! $onLeaveToday && ! ($today && $today->check_in_at && $today->check_out_at))
                        <form method="POST" action="{{ route('employees.my_attendance.mark') }}" id="attendanceForm"
                            x-ref="form" @submit.prevent="markAttendance()">
                            @csrf
                            <input type="hidden" name="latitude" :value="latitude" />
                            <input type="hidden" name="longitude" :value="longitude" />
                            <input type="hidden" name="qr_text" :value="qrText" />
                            <x-button type="submit" icon="clock" class="w-full" ::disabled="locating">
                                <span x-text="locating ? 'Locating…' : @js($action)"></span>
                            </x-button>
                        </form>
                    @endif

                    @if ($today && $today->check_in_at && $today->check_out_at)
                        <div class="border-t border-line pt-4">
                            <p class="text-sm text-ink-faint text-center">Attendance completed for today</p>
                        </div>
                    @endif
                </div>
            </x-card>

            <x-card title="This month" :padding="false" class="lg:col-span-2">
                @php
                    $present = $records->where('status', 'present')->count();
                    $late = $records->where('status', 'late')->count();
                    $shortLeave = $records->where('status', 'short_leave')->count();
                    $halfDay = $records->where('status', 'half_day')->count();
                    $absent = $records->where('status', 'absent')->count();
                @endphp
                <div class="grid grid-cols-5 gap-4 border-b border-line px-5 py-4">
                    <x-stat-card label="Present" :value="$present" icon="check" tone="success" />
                    <x-stat-card label="Late" :value="$late" icon="clock" tone="warning" />
                    <x-stat-card label="Short leave" :value="$shortLeave" icon="clock" tone="info" />
                    <x-stat-card label="Half day" :value="$halfDay" icon="clock" tone="warning" />
                    <x-stat-card label="Absent" :value="$absent" icon="x" tone="danger" />
                </div>

                @if ($records->isEmpty())
                    <p class="px-5 py-6 text-sm text-ink-faint">No attendance records this month.</p>
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>In</th>
                                    <th>Out</th>
                                    <th class="text-right">Work</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($records as $record)
                                    <tr>
                                        <td class="text-ink">{{ $record->attendance_date->format('Y-m-d') }}</td>
                                        <td class="text-ink-soft">{{ $record->check_in_at?->format('H:i') ?: '—' }}</td>
                                        <td class="text-ink-soft">{{ $record->check_out_at?->format('H:i') ?: '—' }}</td>
                                        <td class="text-right text-ink-soft">{{ $record->work_minutes ? gmdate('H:i', $record->work_minutes * 60) : '—' }}</td>
                                        <td>
                                            @if ($record->method)
                                                <x-employees.status-badge :status="$record->method" />
                                            @else
                                                <span class="text-ink-faint">—</span>
                                            @endif
                                        </td>
                                        <td><x-employees.status-badge :status="$record->status ?? 'pending'" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    @endif

    @push('scripts')
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        function attendanceScanner() {
            return {
                latitude: null,
                longitude: null,
                qrText: @json(settings('company.qr_code_text', '')),
                locating: false,
                scanning: false,
                message: '',
                success: false,
                reader: null,
                cameras: [],
                lastQr: '',

                /**
                 * Read the device GPS. Resolves with the coordinates so callers can
                 * wait for them instead of submitting a form with null values.
                 */
                getLocation() {
                    return new Promise((resolve, reject) => {
                        if (! navigator.geolocation) {
                            reject(new Error('This browser cannot report your location.'));
                            return;
                        }

                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                this.latitude = position.coords.latitude;
                                this.longitude = position.coords.longitude;
                                resolve([this.latitude, this.longitude]);
                            },
                            (error) => {
                                const messages = {
                                    1: 'Location permission was denied. Allow location access to mark attendance.',
                                    2: 'Your location is unavailable. Turn on location services and try again.',
                                    3: 'Locating timed out. Please try again.',
                                };
                                reject(new Error(messages[error.code] ?? 'Could not determine your location.'));
                            },
                            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                        );
                    });
                },

                /**
                 * Open the rear camera and scan continuously. The file input is only
                 * a fallback because on some browsers `capture` still opens the
                 * gallery chooser instead of the camera.
                 */
                async toggleScanner() {
                    if (this.scanning) {
                        this.stopScanner();
                        return;
                    }

                    if (typeof Html5Qrcode === 'undefined') {
                        this.message = 'QR scanner library not loaded. Please refresh the page.';
                        this.success = false;
                        return;
                    }

                    this.message = 'Requesting camera access…';
                    this.success = false;

                    try {
                        this.cameras = await Html5Qrcode.getCameras();
                    } catch (e) {
                        this.message = 'Could not access the camera. Use “Upload QR image” instead.';
                        this.success = false;
                        return;
                    }

                    if (! this.cameras.length) {
                        this.message = 'No camera found on this device. Use “Upload QR image” instead.';
                        this.success = false;
                        return;
                    }

                    const readerElement = document.getElementById('qr-reader');
                    readerElement.classList.remove('hidden');
                    this.reader = new Html5Qrcode('qr-reader');

                    try {
                        await this.reader.start(
                            { facingMode: 'environment' },
                            { fps: 10, qrbox: { width: 220, height: 220 }, aspectRatio: 1 },
                            (decodedText) => this.onDecoded(decodedText),
                            () => {}
                        );
                        this.scanning = true;
                        this.message = 'Point the camera at the office QR code.';
                        this.success = true;
                    } catch (e) {
                        readerElement.classList.add('hidden');
                        this.message = 'Could not start the camera. Use “Upload QR image” instead.';
                        this.success = false;
                    }
                },

                stopScanner() {
                    if (this.reader && this.scanning) {
                        this.reader.stop().then(() => this.reader.clear()).catch(() => {});
                    }
                    this.scanning = false;

                    const readerElement = document.getElementById('qr-reader');
                    if (readerElement) {
                        readerElement.classList.add('hidden');
                        readerElement.innerHTML = '';
                    }
                },

                onDecoded(decodedText) {
                    // The camera fires the callback on every frame, so ignore repeats.
                    if (! decodedText || decodedText === this.lastQr) return;
                    this.lastQr = decodedText;

                    this.qrText = decodedText;
                    this.stopScanner();
                    this.markAttendance();
                },

                async handleQrUpload(event) {
                    const file = event.target.files[0];
                    if (! file) return;

                    if (typeof Html5Qrcode === 'undefined') {
                        this.message = 'QR scanner library not loaded. Please refresh the page.';
                        this.success = false;
                        return;
                    }

                    this.message = 'Reading QR code…';
                    this.success = false;

                    try {
                        const readerElement = document.getElementById('qr-reader');
                        readerElement.classList.remove('hidden');
                        this.reader = new Html5Qrcode('qr-reader');
                        const decodedText = await this.reader.scanFile(file, true);
                        readerElement.classList.add('hidden');
                        readerElement.innerHTML = '';
                        this.onDecoded(decodedText);
                    } catch (e) {
                        const readerElement = document.getElementById('qr-reader');
                        if (readerElement) {
                            readerElement.classList.add('hidden');
                            readerElement.innerHTML = '';
                        }
                        this.message = 'Could not read a QR code from that image. Please try again.';
                        this.success = false;
                    } finally {
                        event.target.value = '';
                    }
                },

                /**
                 * Resolve the device location first, then submit. Submitting before
                 * the GPS callback fires is what produced "The latitude field is
                 * required".
                 */
                async markAttendance() {
                    if (this.locating) return;

                    this.locating = true;
                    this.message = 'Locating you…';
                    this.success = false;

                    try {
                        await this.getLocation();
                    } catch (e) {
                        this.message = e.message;
                        this.success = false;
                        this.locating = false;
                        return;
                    }

                    this.locating = false;
                    this.message = '';
                    this.$refs.form.requestSubmit();
                },

                destroy() {
                    this.stopScanner();
                }
            }
        }
    </script>
    @endpush
</x-app-layout>

