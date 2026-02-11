# Avalaunch Address Services

A WordPress plugin that leverages the Google Maps API for address autocomplete and integrates with Salesforce to fetch available services for a specific location.

## Description

This plugin provides a shortcode to display an address search bar. When a user selects an address, the plugin retrieves address components (street, city, zip code, country) and queries Salesforce to check for service availability in that area.

## Features

*   **Google Maps Autocomplete**: User-friendly address search.
*   **Salesforce Integration**: Real-time service availability checks.
*   **Shortcode Support**: Easily embed the search bar anywhere using `[avalaunch_address_search]`.

## Installation

1.  Upload the `avalaunch-address-services` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to **Settings > Address Services** in the WordPress admin menu to configure your API Credentials.

## Configuration

The following credentials are required for the plugin to function:

*   **Google Maps API Key**: Must have the "Places API" and "Maps JavaScript API" enabled.
*   **Salesforce Consumer Key & Secret**: For OAuth2 authentication with Salesforce.

## Usage

Add the following shortcode to any page or post:

```
[avalaunch_address_search]
```
