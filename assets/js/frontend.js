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
   Entry point called by Google Maps callback
   ============================================================ */
window.initAvalaunchMap = function () {
    var container = document.getElementById('avalaunch-place-container');
    if (!container) {
        console.error('Avalaunch: #avalaunch-place-container not found.');
        return;
    }

    /* --- Get or Create address input --- */
    var input = document.getElementById('avalaunch-address-input');
    if (!input) {
        input = document.createElement('input');
        input.type = 'text';
        input.id = 'avalaunch-address-input';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('placeholder', 'Enter your address to check availability...');
        container.appendChild(input);
    }

    /* --- Attach Google Autocomplete (US addresses only) --- */
    var autocomplete = new google.maps.places.Autocomplete(input, {
        fields: ['formatted_address', 'address_components'],
        types: ['address'],
        componentRestrictions: { country: 'us' }
    });

    /* --- Search button --- */
    var searchBtn = document.getElementById('aas-search-btn');
    var selectedPlace = null; // Tracks the last place selected from the dropdown

    // Store the place whenever the user picks from the dropdown.
    // Do NOT auto-search here — wait for the button click so the user
    // can optionally fill in the Unit / Apt field first.
    autocomplete.addListener('place_changed', function () {
        selectedPlace = autocomplete.getPlace();
    });

    if (searchBtn) {
        searchBtn.addEventListener('click', function () {
            var inputVal = input.value.trim();

            // Case 1: Input is empty
            if (!inputVal) {
                showInlineError('Please enter your address.');
                return;
            }

            // Case 2: User typed something but never selected from the dropdown
            if (!selectedPlace || !selectedPlace.address_components) {
                showInlineError('Please select an address from the suggestions list.');
                return;
            }

            // Case 3: Valid place already selected — proceed
            handlePlace(selectedPlace);
        });
    }

    // Reset selected place if user edits the address input manually.
    // The unit field is intentionally excluded — editing it should not clear the selection.
    input.addEventListener('input', function () {
        selectedPlace = null;
    });

    /* --- Clear Results --- */
    var clearBtn = document.getElementById('aas-clear-results');
    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            clearAll(input);
        });
    }

    // Move the contact modal overlay to <body> so it is never trapped
    // inside a parent stacking context (transform, filter, will-change, etc.)
    // that would prevent it from appearing above the site navbar.
    var $overlay = jQuery('#aas-contact-modal-overlay');
    if ($overlay.length && $overlay.parent().attr('id') !== 'aas-body-root') {
        jQuery('body').append($overlay.detach());
    }

    jQuery(document).on('click', '#aas-contact-modal-close, #aas-contact-modal-overlay', function (e) {
        if (e.target === this) {
            jQuery('#aas-contact-modal-overlay').fadeOut();
        }
    });

    jQuery(document).on('click', '.aas-order-now-btn', function (e) {
        e.preventDefault();
        jQuery('#aas-contact-modal-overlay').css('display', 'flex').hide().fadeIn();
    });

};


/* ============================================================
   Handle a selected place → parse address → query Salesforce
   ============================================================ */
function handlePlace(place) {
    var $ = jQuery;

    if (!place || !place.address_components) {
        showInlineError('Please select an address from the suggestions list.');
        return;
    }

    // --- Extract street number and route from Google address_components ---
    var streetNumber = '';
    var route        = '';

    for (var i = 0; i < place.address_components.length; i++) {
        var comp  = place.address_components[i];
        var types = comp.types;
        if (types.indexOf('street_number') !== -1) { streetNumber = comp.long_name; }
        if (types.indexOf('route')         !== -1) { route        = comp.long_name; }
    }

    if (!streetNumber || !route) {
        showInlineError('Please select a specific street address (not just a city or zip).');
        return;
    }

    // Extract the first two non-directional, non-suffix words from the route.
    // e.g. "S Creek Run Way" → keyword1='Creek', keyword2='Run'
    // e.g. "Main St"        → keyword1='Main',  keyword2=''
    var directionals = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW',
                        'North', 'South', 'East', 'West'];
    var suffixes     = ['St', 'Ave', 'Blvd', 'Dr', 'Way', 'Rd', 'Ln',
                        'Ct', 'Pl', 'Trl', 'Pkwy', 'Cir', 'Loop',
                        'Street', 'Avenue', 'Boulevard', 'Drive', 'Road',
                        'Lane', 'Court', 'Place', 'Trail', 'Parkway', 'Circle'];
    var skip         = directionals.concat(suffixes);
    var routeWords   = route.split(' ');
    var keywords     = [];
    for (var j = 0; j < routeWords.length && keywords.length < 2; j++) {
        var word = routeWords[j];
        if (skip.indexOf(word) === -1 && word.length > 1) {
            keywords.push(word);
        }
    }
    // Fallback: if nothing usable found, use the first word verbatim.
    if (keywords.length === 0) { keywords.push(routeWords[0]); }

    var streetKeyword  = keywords[0] || '';
    var streetKeyword2 = keywords[1] || '';

    var unit = (document.getElementById('avalaunch-unit-input') || {}).value || '';
    unit = unit.trim();

    var displayAddress = escHtml(place.formatted_address);
    if (unit) {
        displayAddress += ' <span style="color:#6b7280;">Unit ' + escHtml(unit) + '</span>';
    }

    /* --- Loading state --- */
    showResultsCard();
    $('#avalaunch-services-results').html('<p class="aas-message">Checking availability for ' + displayAddress + '...</p>');

    $.ajax({
        url: avalaunch_vars.ajax_url,
        type: 'POST',
        data: {
            action:          'avalaunch_get_services',
            nonce:           avalaunch_vars.nonce,
            street_number:   streetNumber,
            street_keyword:  streetKeyword,
            street_keyword2: streetKeyword2,
            unit:            unit
        },
        success: function (response) {
            var $results = $('#avalaunch-services-results');

            if (response.success) {
                var data = response.data;

                if (data.has_coverage) {
                    /* ✅ Service Available — show result card then open contact modal */
                    $results.html(
                        '<div class="aas-status-card aas-status-available">'
                        + '<div class="aas-status-icon">✓</div>'
                        + '<div class="aas-status-text">'
                        + '<strong>Good News! Fiber is available at your address.</strong>'
                        + '<a href="#" class="aas-btn-primary aas-order-now-btn" style="color:#e97b37; text-decoration:none; font-weight:600; font-size:15px; margin-top:8px; display:inline-block;">Connect With Us</a>'
                        + '</div>'
                        + '</div>'
                    );
                    // Automatically open the contact modal.
                    jQuery('#aas-contact-modal-overlay').css('display', 'flex').hide().fadeIn();
                } else {
                    /* ❌ No Coverage */
                    $results.html(
                        '<div class="aas-status-card aas-status-no-coverage">'
                        + '<div class="aas-status-icon">✕</div>'
                        + '<div class="aas-status-text">'
                        + '<strong>No service available at this address.</strong>'
                        + '<p>We haven\'t reached your area yet, but we\'re expanding. Call us at <a href="tel:8013223278" style="color:inherit;font-weight:600;">801-322-3278</a> or email <a href="mailto:customerservice@fastel.com" style="color:inherit;font-weight:600;">customerservice@fastel.com</a> for more information.</p>'
                        + '</div>'
                        + '</div>'
                    );
                }
            } else {
                $results.html('<p class="aas-message error">Error: ' + escHtml(response.data.message) + '</p>');
            }
        },
        error: function () {
            $('#avalaunch-services-results').html('<p class="aas-message error">Connection error. Please try again later.</p>');
        }
    });
}

/* ============================================================
   UI helpers
   ============================================================ */
function clearAll(input) {
    jQuery('#aas-results-card').hide();
    jQuery('#avalaunch-services-results').empty();
    if (input) { input.value = ''; input.focus(); }
}

function showResultsCard() {
    jQuery('#aas-results-card').show().addClass('aas-visible');
}

function showInlineError(message) {
    showResultsCard();
    jQuery('#avalaunch-services-results').html('<p class="aas-message error">' + escHtml(message) + '</p>');
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escAttr(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}