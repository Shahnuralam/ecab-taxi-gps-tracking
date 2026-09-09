(function () {
	'use strict';
	if (!window.MPTBMGPS) return;

	const cfg = window.MPTBMGPS;
	const request = async (path, options) => {
		options = options || {};
		const headers = Object.assign({'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce}, options.headers || {});
		const response = await fetch(cfg.restUrl + path, Object.assign({credentials: 'same-origin'}, options, {headers: headers}));
		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			const error = new Error(data.message || cfg.i18n.error);
			error.status = response.status;
			error.code = data.code || '';
			throw error;
		}
		return data;
	};

	const mapStates = new WeakMap();
	const validPoint = point => point && Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude));
	const latLng = point => [Number(point.latitude), Number(point.longitude)];
	const markerIcon = (type, heading) => window.L.divIcon({
		className: 'mptbm-gps-marker-wrap',
		html: '<span class="mptbm-gps-marker is-' + type + '"' + (type === 'driver' ? ' style="--gps-heading:' + Number(heading || 0) + 'deg"' : '') + '></span>',
		iconSize: [30, 30], iconAnchor: [15, 15]
	});
	const phaseText = phase => ({
		to_pickup: 'Driver is on the way to your pickup.',
		to_destination: 'Your trip is heading to the destination.',
		arrived_destination: 'The driver has reached the destination.'
	}[phase] || 'Driver location is live.');
	const formatDistance = metres => metres >= 1000 ? (metres / 1000).toFixed(metres >= 10000 ? 0 : 1) + ' km' : Math.round(metres) + ' m';
	const formatDuration = seconds => {
		const minutes = Math.max(1, Math.round(seconds / 60));
		return minutes >= 60 ? Math.floor(minutes / 60) + ' hr ' + (minutes % 60) + ' min' : minutes + ' min';
	};
	const storageGet = key => { try { return window.localStorage.getItem(key); } catch (error) { return null; } };
	const storageSet = (key, value) => { try { window.localStorage.setItem(key, value); } catch (error) {} };
	const storageRemove = key => { try { window.localStorage.removeItem(key); } catch (error) {} };
	const trackingStorageKey = 'mptbmGpsActiveBooking:' + Number(cfg.userId || 0);
	const notificationStorageKey = bookingId => 'mptbmGpsNotifications:' + Number(bookingId);
	const notificationPhaseKey = bookingId => 'mptbmGpsNotifiedPhase:' + Number(bookingId);
	const setIndicator = (element, state, text) => {
		if (!element) return;
		element.className = state ? 'is-' + state : '';
		element.lastChild.textContent = text;
	};
	const initDeviceHealth = root => {
		const network = root.querySelector('[data-gps-network]');
		const signal = root.querySelector('[data-gps-signal]');
		const batteryElement = root.querySelector('[data-gps-battery]');
		const updateNetwork = () => {
			const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
			const type = connection && connection.effectiveType ? ' (' + connection.effectiveType.toUpperCase() + ')' : '';
			setIndicator(network, navigator.onLine ? 'good' : 'error', navigator.onLine ? 'Network: online' + type : 'Network: offline');
		};
		updateNetwork();
		window.addEventListener('online', updateNetwork);
		window.addEventListener('offline', updateNetwork);
		if (navigator.connection && navigator.connection.addEventListener) navigator.connection.addEventListener('change', updateNetwork);
		if (navigator.getBattery) {
			navigator.getBattery().then(battery => {
				const updateBattery = () => {
					const level = Math.round(battery.level * 100);
					setIndicator(batteryElement, level < 20 && !battery.charging ? 'warning' : 'good', 'Battery: ' + level + '%' + (battery.charging ? ' charging' : ''));
				};
				updateBattery();
				battery.addEventListener('levelchange', updateBattery);
				battery.addEventListener('chargingchange', updateBattery);
			}).catch(() => setIndicator(batteryElement, '', 'Battery: unavailable'));
		} else {
			setIndicator(batteryElement, '', 'Battery: unavailable');
		}
		return {
			waiting: () => setIndicator(signal, 'warning', 'GPS: waiting'),
			connected: accuracy => setIndicator(signal, 'good', 'GPS: connected ±' + Math.round(Number(accuracy) || 0) + ' m'),
			error: () => setIndicator(signal, 'error', 'GPS: unavailable')
		};
	};
	const showCustomerNotification = (bookingId, phase) => {
		if (!cfg.customerNotifications || !('Notification' in window) || Notification.permission !== 'granted' || storageGet(notificationStorageKey(bookingId)) !== 'yes') return;
		const messages = {
			to_destination: ['Your driver has arrived', 'Your driver has reached the pickup location.'],
			arrived_destination: ['Destination reached', 'Your taxi has reached the destination.']
		};
		if (!messages[phase] || storageGet(notificationPhaseKey(bookingId)) === phase) return;
		storageSet(notificationPhaseKey(bookingId), phase);
		const options = {body: messages[phase][1], icon: cfg.icon, badge: cfg.icon, tag: 'mptbm-gps-' + bookingId + '-' + phase, data: {url: window.location.href}};
		if ('serviceWorker' in navigator) {
			navigator.serviceWorker.ready.then(registration => registration.showNotification(messages[phase][0], options)).catch(() => new Notification(messages[phase][0], options));
		} else {
			new Notification(messages[phase][0], options);
		}
	};

	const showMap = (root, data) => {
		const map = root.querySelector('[data-gps-map]');
		if (!map || !data || typeof window.L === 'undefined') return;
		const trip = data.trip || {};
		const driver = validPoint(data) ? data : null;
		if (!driver && !validPoint(trip.pickup) && !validPoint(trip.destination)) return;
		let state = mapStates.get(map);
		if (!state) {
			map.replaceChildren();
			state = {map: window.L.map(map, {zoomControl: true}), markers: {}, trail: null, route: null, fitted: false};
			window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'}).addTo(state.map);
			mapStates.set(map, state);
		}
		[['pickup', trip.pickup], ['destination', trip.destination], ['driver', driver]].forEach(([type, point]) => {
			if (!validPoint(point)) return;
			if (!state.markers[type]) state.markers[type] = window.L.marker(latLng(point), {icon: markerIcon(type, data.heading)}).addTo(state.map);
			else state.markers[type].setLatLng(latLng(point)).setIcon(markerIcon(type, data.heading));
			state.markers[type].bindTooltip(type === 'driver' ? (data.driver || 'Driver') : (point.label || (type === 'pickup' ? 'Pickup' : 'Destination')));
		});
		const trail = Array.isArray(data.trail) ? data.trail.filter(validPoint).map(latLng) : [];
		if (driver && (!trail.length || trail[trail.length - 1][0] !== Number(data.latitude) || trail[trail.length - 1][1] !== Number(data.longitude))) trail.push(latLng(driver));
		if (state.trail) state.trail.setLatLngs(trail);
		else state.trail = window.L.polyline(trail, {color: '#635bff', weight: 4, opacity: 0.75}).addTo(state.map);
		const target = trip.phase === 'to_pickup' ? trip.pickup : trip.destination;
		const route = driver && validPoint(target) ? [latLng(driver), latLng(target)] : [];
		if (state.route) state.route.setLatLngs(route);
		else state.route = window.L.polyline(route, {color: '#0f766e', weight: 3, opacity: 0.65, dashArray: '8 8'}).addTo(state.map);
		const visible = [driver, trip.pickup, trip.destination].filter(validPoint).map(latLng);
		if (!state.fitted && visible.length) {
			state.map.fitBounds(visible, {padding: [35, 35], maxZoom: 16});
			state.fitted = true;
		} else if (driver && !state.map.getBounds().pad(-0.15).contains(latLng(driver))) {
			state.map.panTo(latLng(driver));
		}
	};

	const loadBookings = async (root) => {
		const select = root.querySelector('#mptbm-gps-booking');
		if (!select) return;
		try {
			const items = await request('driver/bookings');
			select.replaceChildren();
			const placeholder = document.createElement('option');
			placeholder.value = '';
			placeholder.textContent = 'Select a booking';
			select.appendChild(placeholder);
			items.forEach(item => {
				const option = document.createElement('option');
				option.value = String(Number(item.id));
				option.textContent = '#' + (item.reference || item.id) + ' — ' + (item.pickup || item.date || 'Booking') + (item.active ? ' (tracking live)' : (item.driverAvailable ? ' (driver online)' : ''));
				if (item.trackingUrl) option.dataset.trackingUrl = item.trackingUrl;
				if (item.pickupPoint) option.dataset.pickupPoint = JSON.stringify(item.pickupPoint);
				if (item.destinationPoint) option.dataset.destinationPoint = JSON.stringify(item.destinationPoint);
				option.dataset.phase = item.phase || 'to_pickup';
				select.appendChild(option);
			});
			return items;
		} catch (error) {
			root.querySelector('[data-gps-status]').textContent = error.message;
			return null;
		}
	};

	const initDriver = (root) => {
		const select = root.querySelector('#mptbm-gps-booking');
		const start = root.querySelector('[data-gps-start]');
		const stop = root.querySelector('[data-gps-stop]');
		const status = root.querySelector('[data-gps-status]');
		const availabilityPanel = root.querySelector('[data-gps-availability-panel]');
		const availabilityToggle = root.querySelector('[data-gps-availability]');
		const availabilityLabel = root.querySelector('[data-gps-availability-label]');
		const health = initDeviceHealth(root);
		let watchId = null;
		let lastSent = 0;
		let activeBooking = 0;
		let pendingSave = Promise.resolve();
		const selectedTrip = () => {
			const option = select.options[select.selectedIndex];
			if (!option) return {};
			const parse = value => { try { return value ? JSON.parse(value) : null; } catch (error) { return null; } };
			return {pickup: parse(option.dataset.pickupPoint), destination: parse(option.dataset.destinationPoint), phase: option.dataset.phase || 'to_pickup'};
		};
		const paintAvailability = available => {
			if (!availabilityToggle) return;
			availabilityToggle.checked = available;
			availabilityPanel.classList.toggle('is-online', available);
			availabilityLabel.textContent = available ? 'You are online' : 'You are offline';
		};
		const updateAvailability = async available => {
			if (!availabilityToggle) return;
			paintAvailability(available);
			const data = await request('driver/status', {method: 'POST', body: JSON.stringify({available: available})});
			paintAvailability(Boolean(data.available));
		};
		const stopTracking = async (notifyServer, preserveStatus, clearResume) => {
			const bookingToStop = activeBooking;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			watchId = null;
			activeBooking = 0;
			if (clearResume !== false) storageRemove(trackingStorageKey);
			if (notifyServer && bookingToStop) {
				try {
					await pendingSave.catch(() => {});
					await request('location/' + bookingToStop, {method: 'DELETE'});
				} catch (error) { status.textContent = error.message; }
			}
			select.disabled = false;
			start.disabled = !select.value;
			stop.disabled = true;
			health.waiting();
			if (!preserveStatus) {
				status.className = 'mptbm-gps-status';
				status.textContent = 'Location sharing is off.';
			}
		};
		const startTracking = isResume => {
			if (watchId !== null) return;
			if (!window.isSecureContext) { status.textContent = cfg.i18n.insecure; return; }
			if (!navigator.geolocation) { health.error(); status.textContent = cfg.i18n.error; return; }
			activeBooking = Number(select.value || 0);
			if (!activeBooking) { status.textContent = 'Choose an assigned booking first.'; return; }
			storageSet(trackingStorageKey, String(activeBooking));
			if (availabilityToggle && !availabilityToggle.checked) updateAvailability(true).catch(error => { status.textContent = error.message; });
			lastSent = 0;
			start.disabled = true;
			stop.disabled = false;
			select.disabled = true;
			health.waiting();
			status.className = 'mptbm-gps-status';
			status.textContent = isResume ? 'Restoring location sharing…' : cfg.i18n.permission;
			watchId = navigator.geolocation.watchPosition(async position => {
				const now = Date.now();
				health.connected(position.coords.accuracy);
				showMap(root, Object.assign({}, position.coords, {trip: selectedTrip()}));
				if (now - lastSent < cfg.interval) return;
				lastSent = now;
				try {
					const bookingId = activeBooking;
					pendingSave = pendingSave.catch(() => {}).then(() => request('location/' + bookingId, {method: 'POST', body: JSON.stringify({
						latitude: position.coords.latitude, longitude: position.coords.longitude,
						accuracy: position.coords.accuracy, speed: position.coords.speed, heading: position.coords.heading
					})}));
					const data = await pendingSave;
					const option = select.options[select.selectedIndex];
					const phase = data.phase || (option && option.dataset.phase) || 'to_pickup';
					if (option && data.phase) option.dataset.phase = data.phase;
					status.className = 'mptbm-gps-status is-live';
					status.textContent = phaseText(phase) + ' Last sent ' + new Date().toLocaleTimeString() + '.';
				} catch (error) {
					status.className = 'mptbm-gps-status is-error';
					status.textContent = error.message;
					if (error.status === 409) stopTracking(false, true, true);
				}
			}, error => {
				health.error();
				status.className = 'mptbm-gps-status is-error';
				status.textContent = error.code === 1 ? cfg.i18n.blocked : error.message || cfg.i18n.error;
				stopTracking(false, true, error.code !== 1 ? false : true);
			}, {enableHighAccuracy: cfg.highAccuracy, maximumAge: Math.max(1000, cfg.interval / 2), timeout: 20000});
		};

		if (availabilityToggle) {
			request('driver/status').then(data => {
				paintAvailability(Boolean(data.available));
				availabilityToggle.disabled = false;
			}).catch(error => { availabilityLabel.textContent = error.message; });
			availabilityToggle.addEventListener('change', async () => {
				const available = availabilityToggle.checked;
				availabilityToggle.disabled = true;
				try {
					if (!available && watchId !== null) await stopTracking(true, false);
					await updateAvailability(available);
				} catch (error) {
					paintAvailability(!available);
					status.className = 'mptbm-gps-status is-error';
					status.textContent = error.message;
				} finally {
					availabilityToggle.disabled = false;
				}
			});
		}
		loadBookings(root).then(items => {
			if (items === null) return;
			if (!items.length) {
				select.disabled = true;
				start.disabled = true;
				storageRemove(trackingStorageKey);
				status.className = 'mptbm-gps-status is-stale';
				status.textContent = cfg.i18n.noBookings;
				return;
			}
			const savedBooking = Number(storageGet(trackingStorageKey) || 0);
			if (savedBooking && select.querySelector('option[value="' + savedBooking + '"]')) select.value = String(savedBooking);
			start.disabled = !select.value;
			if (cfg.autoResume && savedBooking && select.value && navigator.permissions && navigator.permissions.query) {
				navigator.permissions.query({name: 'geolocation'}).then(permission => {
					if (permission.state === 'granted') startTracking(true);
					else status.textContent = 'Previous tracking session found. Tap Start to resume location sharing.';
				}).catch(() => { status.textContent = 'Previous tracking session found. Tap Start to resume location sharing.'; });
			}
		});
		select.addEventListener('change', () => { if (watchId === null) start.disabled = !select.value; });
		start.addEventListener('click', () => startTracking(false));
		stop.addEventListener('click', () => stopTracking(true, false));
		window.addEventListener('pagehide', () => { if (watchId !== null) navigator.geolocation.clearWatch(watchId); });
	};

	const initViewer = (root) => {
		const status = root.querySelector('[data-gps-status]');
		const details = root.querySelector('[data-gps-details]');
		const select = root.querySelector('#mptbm-gps-booking');
		const notificationButton = root.querySelector('[data-gps-notifications]');
		let bookingId = Number(root.dataset.bookingId || 0);
		const token = root.dataset.token || '';
		if (notificationButton) {
			const paintNotificationButton = () => {
				const enabled = 'Notification' in window && Notification.permission === 'granted' && storageGet(notificationStorageKey(bookingId)) === 'yes';
				notificationButton.classList.toggle('is-enabled', enabled);
				notificationButton.textContent = enabled ? 'Alerts enabled' : ('Notification' in window && Notification.permission !== 'denied' ? 'Enable alerts' : 'Alerts blocked');
			};
			paintNotificationButton();
			notificationButton.addEventListener('click', async () => {
				if (!('Notification' in window)) return;
				if (storageGet(notificationStorageKey(bookingId)) === 'yes' && Notification.permission === 'granted') {
					storageRemove(notificationStorageKey(bookingId));
					paintNotificationButton();
					return;
				}
				const permission = await Notification.requestPermission();
				if (permission === 'granted') storageSet(notificationStorageKey(bookingId), 'yes');
				paintNotificationButton();
			});
		}
		if (root.dataset.mode === 'admin') {
			loadBookings(root);
			select.addEventListener('change', () => {
				bookingId = Number(select.value || 0);
				const share = root.querySelector('[data-gps-share]');
				const selected = select.options[select.selectedIndex];
				if (share) {
					share.href = selected && selected.dataset.trackingUrl ? selected.dataset.trackingUrl : '#';
					share.hidden = !selected || !selected.dataset.trackingUrl;
				}
				if (bookingId) poll();
			});
		}
		const poll = async () => {
			if (!bookingId) return;
			try {
				const data = await request('location/' + bookingId, {method: 'GET', headers: {'X-MPTBM-GPS-Token': token}});
				if (!data.available) {
					showMap(root, data);
					status.className = 'mptbm-gps-status ' + (data.driverAvailable ? 'is-live' : 'is-stale');
					status.textContent = data.driverAvailable ? 'Driver is online. Waiting for location sharing to start…' : 'Driver is currently offline.';
					details.textContent = data.driverLastSeen ? 'Driver presence updated ' + new Date(data.driverLastSeen).toLocaleString() + '.' : '';
					return;
				}
				showMap(root, data);
				if (root.dataset.mode === 'viewer') showCustomerNotification(bookingId, data.trip && data.trip.phase);
				const age = Math.max(0, Math.round((Date.now() - new Date(data.recordedAt).getTime()) / 60000));
				status.className = 'mptbm-gps-status ' + (data.active && age < cfg.staleMinutes ? 'is-live' : 'is-stale');
				status.textContent = data.active ? (age < cfg.staleMinutes ? phaseText(data.trip && data.trip.phase) : 'Location may be stale.') : (data.driverAvailable ? 'Driver is online but location sharing is stopped.' : 'Driver is currently offline.');
				const metrics = data.metrics || {};
				const remaining = Number.isFinite(Number(metrics.remainingDistanceMetres)) ? ' • Remaining: ' + formatDistance(Number(metrics.remainingDistanceMetres)) : '';
				const eta = Number.isFinite(Number(metrics.etaSeconds)) ? ' • ETA: ' + formatDuration(Number(metrics.etaSeconds)) : '';
				const availability = data.driverAvailable ? ' • Driver online' : ' • Driver offline';
				details.textContent = 'Updated ' + new Date(data.recordedAt).toLocaleString() + (data.driver ? ' • Driver: ' + data.driver : '') + availability + remaining + eta + (data.accuracy ? ' • GPS accuracy: ±' + Math.round(data.accuracy) + ' m' : '');
			} catch (error) { status.className = 'mptbm-gps-status is-error'; status.textContent = error.message; }
		};
		poll();
		window.setInterval(poll, Math.max(5000, cfg.interval));
	};

	let installPrompt = null;
	const installButtons = Array.from(document.querySelectorAll('[data-gps-install]'));
	const pwaStatuses = Array.from(document.querySelectorAll('[data-gps-pwa-status]'));
	const setPwaStatus = message => pwaStatuses.forEach(element => { element.textContent = message; });
	const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
	installButtons.forEach(button => {
		button.hidden = isStandalone;
		button.addEventListener('click', async () => {
			if (installPrompt) {
				installPrompt.prompt();
				const choice = await installPrompt.userChoice;
				if (choice.outcome === 'accepted') button.hidden = true;
				installPrompt = null;
				return;
			}
			setPwaStatus(window.isSecureContext ? cfg.i18n.installHelp : cfg.i18n.insecure);
		});
	});
	window.addEventListener('beforeinstallprompt', event => {
		event.preventDefault();
		installPrompt = event;
		installButtons.forEach(button => { button.hidden = false; });
	});
	window.addEventListener('appinstalled', () => {
		installButtons.forEach(button => { button.hidden = true; });
		setPwaStatus(cfg.i18n.pwaReady);
	});
	if ('serviceWorker' in navigator && window.isSecureContext) {
		navigator.serviceWorker.register(cfg.serviceWorker, {scope: cfg.serviceWorkerScope})
			.then(() => navigator.serviceWorker.ready)
			.then(() => setPwaStatus(cfg.i18n.pwaReady))
			.catch(() => setPwaStatus(cfg.i18n.pwaFailed));
	} else {
		setPwaStatus(cfg.i18n.insecure);
	}
	document.querySelectorAll('.mptbm-gps-app').forEach(root => root.dataset.mode === 'driver' ? initDriver(root) : initViewer(root));
})();
