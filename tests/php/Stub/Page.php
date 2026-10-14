<?php

use SilverStripe\CMS\Model\SiteTree;

// silvershop/core expects a global `Page` (CheckoutPage / Product extend it). As a root package under test we
// have no project app/, so this scanned stub provides it. It must be a *scanned* file (not only a runtime
// definition in tests/bootstrap.php) so the SilverStripe class manifest records the Page -> SiteTree ancestry;
// without that, DataObjectSchema cannot resolve a Page subclass's table name and Product::get() / write()
// throw "<field> is not a field on class Product" in the clean room.
class Page extends SiteTree
{
}
