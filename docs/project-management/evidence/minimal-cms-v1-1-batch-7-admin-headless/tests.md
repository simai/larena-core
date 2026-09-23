# Tests

`CatalogOperationExecutionTest`: lazy handler build; create through the registry; bulk move refused without approval, proposed without change, executed with the approval; a different change refused on the same approval; unknown handler reference fails closed and writes nothing; ambient transaction refused; undeclared capability denied. `composer test` runs on commit through the quality gate.
