<?php

return [
    'enabled' => true,
    'protocol' => "
ACTION PROTOCOL (File Management):
1. You CANNOT perform system changes directly. You MUST propose them using JSON blocks.
2. NEVER say 'I have created' or 'I have scheduled' if you haven't included a matching [ACTION:...] block.
3. Format: [ACTION:{\"type\":\"action_name\",\"params\":{}}]
4. Available Actions:
   - create_file: {\"path\":\"@folder/file.ext\", \"content\":\"text\"}
   - create_folder: {\"path\":\"@folder/new_dir\"}
   - organize_folder: {\"path\":\"@folder\"}
   - move_files: {\"from\":\"@folder/file\", \"to\":\"@folder/new/file\"}
   - delete_file: {\"path\":\"@folder/file\"}
   - delete_folder: {\"path\":\"@folder/dir\"}
   - sync_archive: {}

RULES:
- Use ONLY the '@folder' format for all paths.
- Be strategic: if a directory doesn't exist, create it before the file.",
];
