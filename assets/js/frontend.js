// Suppress Google Maps Autocomplete deprecation console warning.
(function () {
    var _warn = console.warn.bind(console);
    console.warn = function () {
        if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('google.maps.places.Autocomplete')) {
            return;
        }
        _warn.apply(console, arguments);
    };
})();

/* ============================================================
   SVG icons
   ============================================================ */
var PIN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>';
var ARROW_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path d="M12 2 L2 22 L12 17 L22 22 Z"/></svg>';

/* ============================================================
   Map state (module-level)
   ============================================================ */
var gMap = null;   // google.maps.Map instance
var gUserMarker = null;   // blue pin for the searched address
var gRadiusCircle = null;   // translucent circle
var gAccountMarkers = [];     // markers for SF accounts
var gInfoWindows = [];     // InfoWindow instances (to close on reopen)

/* Miles → metres conversion */
var MILES_TO_METRES = 1609.344;

/* ============================================================
   Entry point called by Google Maps callback
   ============================================================ */
window.initAvalaunchMap = function () {
    var container = document.getElementById('avalaunch-place-container');
    if (!container) {
        console.error('Avalaunch: #avalaunch-place-container not found.');
        return;
    }

    /* --- Build the map immediately in the map card --- */
    var mapCard = document.getElementById('aas-map-card');
    initMap(mapCard);

    /* --- Create address input --- */
    var input = document.createElement('input');
    input.type = 'text';
    input.id = 'avalaunch-address-input';
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('placeholder', 'Start typing your address…');
    container.appendChild(input);

    /* --- Attach Google Autocomplete --- */
    var autocomplete = new google.maps.places.Autocomplete(input, {
        fields: ['formatted_address', 'address_components', 'geometry', 'name'],
        types: ['address']
    });

    autocomplete.addListener('place_changed', function () {
        handlePlace(autocomplete.getPlace());
    });

    /* --- Search button --- */
    var searchBtn = document.getElementById('aas-search-btn');
    if (searchBtn) {
        searchBtn.addEventListener('click', function () {
            var place = autocomplete.getPlace();
            if (place && place.geometry) {
                handlePlace(place);
            } else {
                google.maps.event.trigger(autocomplete, 'place_changed');
            }
        });
    }

    /* --- Clear Results --- */
    var clearBtn = document.getElementById('aas-clear-results');
    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            clearAll(input);
        });
    }
};

/* ============================================================
   Initialise the Google Map (default view, US centered)
   ============================================================ */
function initMap(container) {
    /* Remove the placeholder label once the real map loads */
    var label = container.querySelector('.aas-map-label');
    var icon = container.querySelector('.aas-map-icon');

    gMap = new google.maps.Map(container, {
        center: { lat: 39.5, lng: -98.35 },
        zoom: 4,
        mapTypeControl: false,
        fullscreenControl: false,
        streetViewControl: false,
        zoomControlOptions: {
            position: google.maps.ControlPosition.RIGHT_BOTTOM
        },
        styles: [
            { featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] },
            { featureType: 'transit', elementType: 'labels', stylers: [{ visibility: 'off' }] }
        ]
    });

    gMap.addListener('tilesloaded', function () {
        if (label) { label.style.display = 'none'; }
        if (icon) { icon.style.display = 'none'; }
    });
}

/* ============================================================
   Handle a selected place → search → render map + cards
   ============================================================ */
function handlePlace(place) {
    var $ = jQuery;

    if (!place || !place.address_components) {
        showResultsCard();
        $('#avalaunch-services-results').html('<p class="aas-message error">Could not read the selected address. Please try again.</p>');
        return;
    }

    var radiusSelect = document.getElementById('avalaunch-radius');
    var radiusMiles = radiusSelect ? parseInt(radiusSelect.value, 10) : 25;
    var radiusLabel = radiusSelect ? radiusSelect.options[radiusSelect.selectedIndex].text : '25 miles';

    var lat = place.geometry && place.geometry.location ? place.geometry.location.lat() : null;
    var lng = place.geometry && place.geometry.location ? place.geometry.location.lng() : null;

    var addressData = {
        formatted_address: place.formatted_address,
        postal_code: '',
        locality: '',
        country: ''
    };
    (place.address_components || []).forEach(function (c) {
        var t = c.types[0];
        if (t === 'postal_code') { addressData.postal_code = c.long_name; }
        if (t === 'locality') { addressData.locality = c.long_name; }
        if (t === 'country') { addressData.country = c.long_name; }
    });

    if (!lat || !lng) {
        showResultsCard();
        $('#avalaunch-services-results').html('<p class="aas-message error">Could not get coordinates for this address. Please try again.</p>');
        return;
    }

    /* --- Loading state --- */
    showResultsCard();
    $('#aas-results-count').text('Searching…');
    $('#avalaunch-services-results').html('<p class="aas-message">Searching within ' + radiusLabel + '…</p>');

    /* --- Place user pin + radius circle immediately --- */
    placeUserMarker(lat, lng);
    drawRadiusCircle(lat, lng, radiusMiles);

    $.ajax({
        url: avalaunch_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'avalaunch_get_services',
            nonce: avalaunch_vars.nonce,
            lat: lat,
            lng: lng,
            radius_miles: radiusMiles,
            address_data: addressData
        },
        success: function (response) {
            var $results = $('#avalaunch-services-results');
            var $count = $('#aas-results-count');

            clearAccountMarkers();

            if (response.success && response.data && response.data.length > 0) {
                var accounts = response.data;
                var count = accounts.length;
                $count.text(count + ' ACCOUNT' + (count > 1 ? 'S' : '') + ' WITHIN ' + radiusLabel.toUpperCase());

                /* Build result cards */
                var html = '';
                accounts.forEach(function (account) {
                    var loc = [account.BillingCity, account.BillingState].filter(Boolean).join(', ');
                    var distBadge = (account.distance_miles !== null && account.distance_miles !== undefined)
                        ? '<span class="aas-distance-badge">' + ARROW_SVG + account.distance_miles + ' mi</span>'
                        : '';
                    var detailsUrl = account.Id ? 'https://na1.salesforce.com/' + account.Id : '#';

                    html += '<div class="aas-account-card">'
                        + '<div class="aas-account-info">'
                        + '<div class="aas-account-name">' + escHtml(account.Name) + '</div>'
                        + (loc ? '<div class="aas-account-loc">' + PIN_SVG + escHtml(loc) + '</div>' : '')
                        + '</div>'
                        + '<div class="aas-account-actions">'
                        + distBadge
                        + '<a class="aas-view-details" href="' + escHtml(detailsUrl) + '" target="_blank" rel="noopener">View Details &rarr;</a>'
                        + '</div>'
                        + '</div>';
                });
                $results.html(html);

                /* Place map markers */
                placeAccountMarkers(accounts, lat, lng, radiusMiles);

            } else if (response.success) {
                $count.text('0 ACCOUNTS WITHIN ' + radiusLabel.toUpperCase());
                $results.html('<p class="aas-message">No accounts found within ' + radiusLabel + ' of this address.</p>');
                fitMapToRadius(lat, lng, radiusMiles);

            } else {
                var msg = response.data && response.data.message ? response.data.message : JSON.stringify(response.data);
                $count.text('Error');
                $results.html('<p class="aas-message error">Error: ' + escHtml(msg) + '</p>');
            }
        },
        error: function (xhr, status, error) {
            $('#aas-results-count').text('Error');
            $('#avalaunch-services-results').html('<p class="aas-message error">Unexpected error: ' + escHtml(error) + '</p>');
        }
    });
}

/* ============================================================
   Map helpers
   ============================================================ */

/**
 * Blue "you are here" marker for the searched address.
 */
function placeUserMarker(lat, lng) {
    if (gUserMarker) { gUserMarker.setMap(null); }
    gUserMarker = new google.maps.Marker({
        position: { lat: lat, lng: lng },
        map: gMap,
        title: 'Your search location',
        zIndex: 10,
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#1e4db7',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2.5
        }
    });
}

/**
 * Translucent indigo circle representing the search radius.
 */
function drawRadiusCircle(lat, lng, radiusMiles) {
    if (gRadiusCircle) { gRadiusCircle.setMap(null); }
    gRadiusCircle = new google.maps.Circle({
        map: gMap,
        center: { lat: lat, lng: lng },
        radius: radiusMiles * MILES_TO_METRES,
        strokeColor: '#4f4b8a',
        strokeOpacity: 0.35,
        strokeWeight: 2,
        fillColor: '#7c78d4',
        fillOpacity: 0.08
    });
    fitMapToRadius(lat, lng, radiusMiles);
}

/**
 * Zoom/pan the map so the full radius circle is visible.
 */
function fitMapToRadius(lat, lng, radiusMiles) {
    if (!gMap || !gRadiusCircle) { return; }
    gMap.fitBounds(gRadiusCircle.getBounds());
}

/**
 * Drop a red marker for each account that has coordinates.
 * Opens an InfoWindow on click with the account name and city.
 */
function placeAccountMarkers(accounts, userLat, userLng, radiusMiles) {
    var bounds = new google.maps.LatLngBounds();

    /* Include the search location */
    bounds.extend({ lat: userLat, lng: userLng });

    accounts.forEach(function (account, idx) {
        var aLat = account.BillingLatitude;
        var aLng = account.BillingLongitude;

        if (!aLat || !aLng) { return; } /* skip accounts without coordinates */

        var marker = new google.maps.Marker({
            position: { lat: aLat, lng: aLng },
            map: gMap,
            title: account.Name,
            zIndex: 5,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 8,
                fillColor: '#ef4444',
                fillOpacity: 0.9,
                strokeColor: '#ffffff',
                strokeWeight: 2
            }
        });

        var loc = [account.BillingCity, account.BillingState].filter(Boolean).join(', ');
        var dist = account.distance_miles !== null && account.distance_miles !== undefined
            ? '<span style="color:#4f4b8a;font-weight:600;">' + account.distance_miles + ' mi away</span>'
            : '';

        var infoWindow = new google.maps.InfoWindow({
            content: '<div style="font-family:Inter,sans-serif;min-width:160px;padding:4px 2px;">'
                + '<strong style="font-size:13px;color:#111827;">' + escHtml(account.Name) + '</strong>'
                + (loc ? '<div style="font-size:12px;color:#6b7280;margin-top:3px;">📍 ' + escHtml(loc) + '</div>' : '')
                + (dist ? '<div style="font-size:12px;margin-top:4px;">' + dist + '</div>' : '')
                + '</div>'
        });

        marker.addListener('click', function () {
            /* Close all other InfoWindows first */
            gInfoWindows.forEach(function (iw) { iw.close(); });
            infoWindow.open(gMap, marker);
        });

        gAccountMarkers.push(marker);
        gInfoWindows.push(infoWindow);
        bounds.extend({ lat: aLat, lng: aLng });
    });

    /* Fit map to include all markers */
    if (!bounds.isEmpty()) {
        gMap.fitBounds(bounds);
        /* Don't zoom in too far if only one/few markers */
        var listener = google.maps.event.addListenerOnce(gMap, 'idle', function () {
            if (gMap.getZoom() > 13) { gMap.setZoom(13); }
        });
    }
}

/**
 * Remove all account markers and InfoWindows from the map.
 */
function clearAccountMarkers() {
    gAccountMarkers.forEach(function (m) { m.setMap(null); });
    gAccountMarkers = [];
    gInfoWindows.forEach(function (iw) { iw.close(); });
    gInfoWindows = [];
}

/* ============================================================
   UI helpers
   ============================================================ */

function showResultsCard() {
    jQuery('#aas-results-card').show();
}

function clearAll(input) {
    /* Hide results card */
    jQuery('#aas-results-card').hide();
    jQuery('#avalaunch-services-results').empty();
    jQuery('#aas-results-count').text('');

    /* Reset input */
    if (input) { input.value = ''; input.focus(); }

    /* Clear map overlays, keep map visible */
    clearAccountMarkers();
    if (gUserMarker) { gUserMarker.setMap(null); gUserMarker = null; }
    if (gRadiusCircle) { gRadiusCircle.setMap(null); gRadiusCircle = null; }

    /* Reset map to default US view */
    if (gMap) {
        gMap.setCenter({ lat: 39.5, lng: -98.35 });
        gMap.setZoom(4);
    }
}

function escHtml(str) {
    if (str === null || str === undefined) { return ''; }
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
