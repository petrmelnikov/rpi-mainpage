# File Index Feature

## Overview
The File Index feature provides a web-based file browser that displays files and directories from a configurable catalog path, with the ability to download directories as compressed archives.

## Features
- **Directory-by-directory navigation** like a traditional file manager
- **Breadcrumb navigation** with clickable path segments
- **File type detection** with appropriate icons and badges
- **File size formatting** in human-readable units (B, KB, MB, GB)
- **Last modified timestamps**
- **Directory download** as gzipped tar archives (.tar.gz)
- **Streaming downloads** without temporary files on server
- **Configurable catalog path** through the settings interface
- **Parent directory navigation** with up arrow button
- **Error handling** for permissions and non-existent paths
- **Responsive Bootstrap UI** with download progress indicators
- **Security protections** against directory traversal attacks

## Configuration
1. Navigate to **Settings** → **File Index Settings** tab
2. Enter the desired catalog path in the "Catalog Path" field
3. Click "Save Changes"

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
- **Navigation**: Click on directory names to enter them
- **Breadcrumb navigation**: Click on any path segment to jump to that directory
- **Parent directory**: Use the "⬆️ Parent Directory" button to go up one level
- **Current directory info**: View directory and file counts with badges
- **Download directories**: Click the "📦 Download" button next to any directory
- **Download current directory**: Click "📦 Download Current Directory" at the top
- Downloads are streamed as .tar.gz files without creating temporary files on the server

## Download Functionality
- **Individual directories**: Each directory has a download button in the Actions column
- **Root catalog download**: Download the entire configured catalog path
- **Archive format**: All downloads are compressed as .tar.gz files
- **Streaming**: Archives are created and streamed in real-time without server storage
- **Security**: Path traversal protection prevents unauthorized access
- **Progress indication**: Download buttons show loading state during archive creation
- **Error handling**: Proper error messages for inaccessible directories

## Requirements
- The specified catalog path must exist and be readable by the web server
- For best performance, limit the catalog to directories with reasonable file counts
- Large directories are automatically limited to 1000 items for performance
- **For downloads**: The server must have `tar` command available (standard on Unix systems)
- **For downloads**: Sufficient permissions to read all files in the target directories
- **For downloads**: Client must support gzipped content and file downloads
