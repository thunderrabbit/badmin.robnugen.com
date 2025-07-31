# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based image upload and management system called "badmin" (b.robnugen.com admin). It provides a web interface for uploading multiple images with descriptions, organizing them into categorized directories, and generating markdown embed codes for use in blogs and Signal messaging.

## Architecture

### Core Components

- **index.php**: Main upload form with 12 image upload slots, category selection, and date prefixing
- **bullet.php**: Core upload processing script that handles file uploads, image resizing, and embed code generation
- **bulletproof_config_sample.php**: Configuration template for password hash and Bulletproof library paths

### Key Features

- Password-protected uploads using PHP's `password_verify()`
- Automatic directory organization by category and year
- Image resizing to create thumbnails (200px) and medium-sized images (1000px) 
- Markdown embed code generation for blog posting
- Direct URL generation for Signal messaging
- Date prefixing for consistent file naming

### Directory Structure

The system organizes uploads into predefined categories:
- `journal/YYYY` - Personal journal entries
- `events/YYYY` - Event photos
- `blog/YYYY` - Blog content
- `mt3cons/YYYY` - Marble Track 3 construction photos
- `mt3parts/YYYY` - Marble Track 3 parts
- `taxj/YYYY` - Tax documents for Japan
- `quests` - Quest-related content
- `tmp` - Temporary uploads

## Development Commands

### Deployment
- `./scp_modified_files_to_badmin.sh` - Watches for file changes and automatically syncs them to the production server using `inotifywait` and `scp`

### Dependencies
- Requires Bulletproof PHP library (external dependency at `/home/thundergoblin/bulletproof/`)
- Uses PHP's built-in image processing functions
- Requires `inotifywait` for file watching during development

## Key Functions

- `create_image_name()` - Handles image naming with date prefixing and space-to-underscore conversion
- `prepend_date_prn()` - Adds date prefix if filename doesn't already contain current year
- `determine_storage_directory()` - Maps category selections to filesystem paths
- `create_thumbnail()` and `create_1000px_nail()` - Image resizing operations
- `embed_markdown_func()` - Generates markdown embed codes
- `urlify()` - Converts filesystem paths to web URLs

## Configuration

- Password hash must be set in `bulletproof_config.php` (use `bulletproof_config_sample.php` as template)
- File paths are hardcoded for the production environment (`/home/thundergoblin/`)
- Debug levels 0-5 control verbosity of output during uploads