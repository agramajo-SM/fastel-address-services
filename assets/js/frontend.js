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
        fields: ['formatted_address', 'geometry'],
        types: ['address'],
        componentRestrictions: { country: 'us' }
    });

    /* --- Search button --- */
    var searchBtn = document.getElementById('aas-search-btn');
    var selectedPlace = null; // Tracks the last place selected from the dropdown

    // Store the place whenever the user picks from the dropdown
    autocomplete.addListener('place_changed', function () {
        selectedPlace = autocomplete.getPlace();
        if (selectedPlace && selectedPlace.geometry) {
            handlePlace(selectedPlace);
        }
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
            if (!selectedPlace || !selectedPlace.geometry) {
                showInlineError('Please select an address from the suggestions list.');
                return;
            }

            // Case 3: Valid place already selected — proceed
            handlePlace(selectedPlace);
        });
    }

    // Reset selected place if user edits the input manually
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

    /* --- Lead capture form submit (event delegation — rendered dynamically) --- */
    jQuery(document).on('click', '.aas-nc-submit', function () {
        var $btn = jQuery(this);
        var $group = $btn.closest('.aas-nc-form-group');
        var $block = $btn.closest('.aas-no-coverage-block');
        var email = $group.find('.aas-nc-input').val().trim();
        var address = $block.data('address') || '';

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showInlineFormError($group, 'Please enter a valid email address.');
            return;
        }

        $btn.prop('disabled', true).text('Sending...');

        jQuery.ajax({
            url: avalaunch_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'avalaunch_create_lead',
                nonce: avalaunch_vars.nonce,
                email: email,
                address: address
            },
            success: function (response) {
                if (response.success) {
                    $block.html(
                        '<div class="aas-lead-success">'
                        + '<div class="aas-status-icon">✓</div>'
                        + '<p><strong>You\'re on the list!</strong></p>'
                        + '<p>We\'ll notify you at <b>' + escHtml(email) + '</b> when fiber arrives at your address.</p>'
                        + '</div>'
                    );
                } else {
                    $btn.prop('disabled', false).text('Submit');
                    showInlineFormError($group, response.data.message || 'Something went wrong. Please try again.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Submit');
                showInlineFormError($group, 'Connection error. Please try again.');
            }
        });
    });
};


/* ============================================================
   Handle a selected place → send coordinates → Salesforce
   ============================================================ */
function handlePlace(place) {
    var $ = jQuery;

    if (!place || !place.geometry || !place.geometry.location) {
        return;
    }

    var lat = place.geometry.location.lat();
    var lng = place.geometry.location.lng();

    /* --- Loading state --- */
    showResultsCard();
    $('#avalaunch-services-results').html('<p class="aas-message">Checking availability for ' + escHtml(place.formatted_address) + '...</p>');

    $.ajax({
        url: avalaunch_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'avalaunch_get_services',
            nonce: avalaunch_vars.nonce,
            lat: lat,
            lng: lng
        },
        success: function (response) {
            var $results = $('#avalaunch-services-results');

            if (response.success) {
                var data = response.data;

                if (data.has_coverage) {
                    if (data.status === 'Active') {
                        /* ✅ Service Available */
                        $results.html(
                            '<div class="aas-status-card aas-status-available">'
                            + '<div class="aas-status-icon">✓</div>'
                            + '<div class="aas-status-text">'
                            + '<strong>Good News! Fiber is available.</strong>'
                            //+ '<p>Service is live at <b>' + escHtml(data.project_name) + '</b>.</p>'
                            + '<a href="#" class="aas-btn-primary aas-order-now-btn" style="color:#e97b37; text-decoration:none; font-weight:600; font-size:15px; margin-top:8px; display:inline-block;">Order Now</a>'
                            + '</div>'
                            + '</div>'
                        );
                    } else {
                        /* 🕐 Coming Soon (Under Construction / Preinstall) */
                        $results.html(
                            '<div class="aas-status-card aas-status-coming-soon">'
                            + '<div class="aas-status-icon">🕐</div>'
                            + '<div class="aas-status-text">'
                            + '<strong>We are coming soon!</strong>'
                            + '<p>Our network is currently under construction at this location.</p>'
                            + '<button class="aas-btn-secondary">Notify Me When Ready</button>'
                            + '</div>'
                            + '</div>'
                        );
                    }
                } else {
                    /* ❌ No Coverage — Lead Capture Form */
                    $results.html(
                        '<div class="aas-no-coverage-block" data-address="' + escAttr(place.formatted_address) + '">'
                        + '<h2 class="aas-nc-title">We haven&rsquo;t made it to your area yet.</h2>'
                        + '<p class="aas-nc-subtitle"> But we&rsquo;re working on it!</p>'
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

function showInlineFormError($group, message) {
    $group.find('.aas-nc-form-error').remove();
    $group.append('<p class="aas-nc-form-error aas-message error">' + escHtml(message) + '</p>');
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escAttr(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}