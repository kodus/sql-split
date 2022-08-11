UPGRADING
=========
## 1.x &rarr; 2.x

### PHP 8.0 required
From version 2.0.0, `kodus/sql-split` requires at least PHP version 8.0

### Typehints added
Typehints were added to properties and method signatures of `Kodus\SQLSplit\Tokenizer` and `Kodus\SQLSplit\Splitter`. 

If you've made classes that extend those, you should update these classes with correct typehints as well.

### Namespace changed
From version 2.0.0, the namespace of the `Splitter` and `Tokenizer` has changed from `Kodus\SQL` to `Kodus\SQLSplit`.

```diff
<?php

-use Kodus\SQLSplit\Splitter;
+use Kodus\SQLSplit\Splitter;
-use Kodus\SQLSplit\Tokenizer;
+use Kodus\SQLSplit\Tokenizer;
```

_The namespace Kodus/SQL collided with a namespace of another (private) kodus repository. The namespace `Kodus\SQL` is
more appropriate for the other repository, so this change was preferred, even though this leads to a
backwards compatibility break for this (public) repository._
