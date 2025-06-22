# File Index Feature

## Overview
The File Index feature provides a web-based file browser that displays files and directories from a configurable catalog path.

## Features
- **Recursive directory scanning** with hierarchical display
- **File type detection** with appropriate icons and badges
- **File size formatting** in human-readable units (B, KB, MB, GB)
- **Last modified timestamps**
- **Configurable catalog path** through the settings interface
- **Performance optimization** with 1000 item limit
- **Error handling** for permissions and non-existent paths
- **Responsive Bootstrap UI**

## Configuration
1. Navigate to **Settings** → **File Index Settings** tab
2. Enter the desired catalog path in the "Catalog Path" field
3. Use the quick-select buttons for common paths
4. Click "Save Changes"

## Supported File Types
The file index displays different icons and badges for various file types:
- 📁 **Directories** - Yellow folder icon with DIR badge
- 💻 **Code files** (.php, .js, .html, .css, .py, etc.) - Computer icon
- 🖼️ **Images** (.jpg, .png, .gif, etc.) - Picture icon
- 📕 **PDF files** - Red book icon
- 🎵 **Audio files** (.mp3, .wav, etc.) - Musical note icon
- 🎬 **Video files** (.mp4, .avi, etc.) - Movie camera icon
- 📦 **Archives** (.zip, .rar, etc.) - Package icon
- 📄 **Generic files** - Document icon

## Configuration File
Settings are stored in `config/file_index.json`:
```json
{
    "catalogPath": "/path/to/your/directory"
}
```

## Usage
- Access via the "file index" button in the navigation
- Or navigate directly to `/file-index`
- Files are sorted with directories first, then alphabetically
- File paths are displayed with proper indentation to show hierarchy
- Click the refresh button to reload the directory listing

## Requirements
- The specified catalog path must exist and be readable by the web server
- For best performance, limit the catalog to directories with reasonable file counts
- Large directories are automatically limited to 1000 items for performance
