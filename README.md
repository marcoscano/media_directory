
# Media Directory

## Overview

This module allows you to use taxonomy terms on Media entities to simulate a
filesystem directory tree. The goal is to provide a IMCE-like experience
but without needing to ever worry about where the assets are really stored in
disk. You can use this functionality with any Media type.

## Dependencies

This module depends on the [jstree](https://github.com/vakata/jstree) library.
Please download it to your `/libraries` folder, making sure the following files
can be reached:

```
/libraries/jstree/dist/jstree.min.js
/libraries/jstree/dist/themes/default/style.min.css
```

## Configuration & Usage

After enabling the module, navigate to `/admin/config/media/media-directory`
and select which Media types should have the directory functionality enabled.
You do this by choosing a vocabulary on the corresponding Media type drop-down.

After you save this configuration form, a field called `media_directory` will
be created on the corresponding Media type, and the directory-tree widget will
be used on this field when you open the Media create / edit form.

@todo Finish me.