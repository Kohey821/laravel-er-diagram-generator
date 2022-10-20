<?php

namespace BeyondCode\ErdGenerator;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use phpDocumentor\GraphViz\Graph;
use Illuminate\Support\Collection;
use phpDocumentor\GraphViz\Node;
use \Illuminate\Database\Eloquent\Model as EloquentModel;

class GraphBuilder
{
    /** @var Graph */
    private $graph;

    /**
     * @param $models
     * @return Graph
     */
    public function buildGraph(Collection $models): Graph
    {
        $this->graph = new Graph();

        foreach (config('erd-generator.graph') as $key => $value) {
            $this->graph->{"set${key}"}($value);
        }

        $this->addModelsToGraph($models);

        return $this->graph;
    }

    protected function getTableColumnsFromModel(EloquentModel $model)
    {
        try {

            $table = $model->getConnection()->getTablePrefix() . $model->getTable();
            $schema = $model->getConnection()->getDoctrineSchemaManager($table);
            $databasePlatform = $schema->getDatabasePlatform();
            $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

            $database = null;

            if (strpos($table, '.')) {
                list($database, $table) = explode('.', $table);
            }

            return $schema->listTableColumns($table, $database);
        } catch (\Throwable $e) {
        }

        return [];
    }

    protected function getModelLabel(EloquentModel $eloquentModel, Model $model, Collection $allRelations)
    {
        $table = '<<table width="100%" height="100%" border="0" margin="0" cellborder="1" cellspacing="0" cellpadding="10">' . PHP_EOL;
        $table .= '<tr width="100%"><td width="100%" bgcolor="' . config('erd-generator.table.header_background_color') . '"><font color="' . config('erd-generator.table.header_font_color') . '">' . $model->getLabel() . '</font></td></tr>' . PHP_EOL;

        /** @var Collection */
        $relations = $allRelations->get($model->getModel());

        if (config('erd-generator.use_db_schema')) {
            $columns = $this->getTableColumnsFromModel($eloquentModel);
            foreach ($columns as $column) {
                $label = $column->getName();

                $relationsOnly = true; // TODO: to config

                if ($relationsOnly) {
                    if (!$relations->contains($label)) {
                        continue;
                    }
                }

                if (config('erd-generator.use_column_types')) {
                    $label .= ' (' . $column->getType()->getName() . ')';
                }
                $table .= '<tr width="100%"><td port="' . $column->getName() . '" align="left" width="100%"  bgcolor="' . config('erd-generator.table.row_background_color') . '"><font color="' . config('erd-generator.table.row_font_color') . '" >' . $label . '</font></td></tr>' . PHP_EOL;
            }
        }

        $table .= '</table>>';

        return $table;
    }

    protected function addModelsToGraph(Collection $models)
    {
        // All relations
        $allRelations = new Collection();
        $models->each(function (Model $model) use ($allRelations) {
            $allRelations->put($model->getModel(), new Collection());
        });
        $models->each(function (Model $model) use ($allRelations) {
            $model->getRelations()->each(function (ModelRelation $relation) use ($allRelations, $model) {
                $allRelations->put(
                    $relation->getModel(),
                    $allRelations->get($relation->getModel())
                        ->merge($relation->getForeignKey())
                );
                $allRelations->put(
                    $model->getModel(),
                    $allRelations->get($model->getModel())
                        ->merge($relation->getLocalKey())
                );
            });
        });

        // Add models to graph
        $models->map(function (Model $model) use ($allRelations) {
            $eloquentModel = app($model->getModel());
            $this->addNodeToGraph($eloquentModel, $model, $allRelations);
        });

        // Create relations
        $models->map(function ($model) {
            $this->addRelationToGraph($model);
        });
    }

    protected function addNodeToGraph(EloquentModel $eloquentModel, Model $model, Collection $allRelations)
    {
        $node = Node::create($model->getNodeName());
        $node->setLabel($this->getModelLabel($eloquentModel, $model, $allRelations));

        foreach (config('erd-generator.node') as $key => $value) {
            $node->{"set${key}"}($value);
        }

        $this->graph->setNode($node);
    }

    protected function addRelationToGraph(Model $model)
    {

        $modelNode = $this->graph->findNode($model->getNodeName());

        /** @var ModelRelation $relation */
        foreach ($model->getRelations() as $relation) {
            $relatedModelNode = $this->graph->findNode($relation->getModelNodeName());

            if ($relatedModelNode !== null) {
                $this->connectByRelation($model, $relation, $modelNode, $relatedModelNode);
            }
        }
    }

    /**
     * @param Node $modelNode
     * @param Node $relatedModelNode
     * @param ModelRelation $relation
     */
    protected function connectNodes(Node $modelNode, Node $relatedModelNode, ModelRelation $relation): void
    {
        $edge = Edge::create($modelNode, $relatedModelNode);
        $edge->setFromPort($relation->getLocalKey());
        $edge->setToPort($relation->getForeignKey());
        $edge->setLabel(' ');
        $edge->setXLabel($relation->getType() . PHP_EOL . $relation->getName());

        foreach (config('erd-generator.edge') as $key => $value) {
            $edge->{"set${key}"}($value);
        }

        foreach (config('erd-generator.relations.' . $relation->getType(), []) as $key => $value) {
            $edge->{"set${key}"}($value);
        }

        $this->graph->link($edge);
    }

    /**
     * @param Model $model
     * @param ModelRelation $relation
     * @param Node $modelNode
     * @param Node $relatedModelNode
     * @return void
     */
    protected function connectBelongsToMany(
        Model $model,
        ModelRelation $relation,
        Node $modelNode,
        Node $relatedModelNode
    ): void {
        $relationName = $relation->getName();
        $eloquentModel = app($model->getModel());

        /** @var BelongsToMany $eloquentRelation */
        $eloquentRelation = $eloquentModel->$relationName();

        if (!$eloquentRelation instanceof BelongsToMany) {
            return;
        }

        $pivotClass = $eloquentRelation->getPivotClass();

        try {
            /** @var EloquentModel $relationModel */
            $pivotModel = app($pivotClass);
            $pivotModel->setTable($eloquentRelation->getTable());
            $label = (new \ReflectionClass($pivotClass))->getShortName();
            $pivotTable = $eloquentRelation->getTable();
            $this->addNodeToGraph($pivotModel, $pivotTable, $label);

            $pivotModelNode = $this->graph->findNode($pivotTable);

            $relation = new ModelRelation(
                $relationName,
                'BelongsToMany',
                $model->getModel(),
                $eloquentRelation->getParent()->getKeyName(),
                $eloquentRelation->getForeignPivotKeyName()
            );

            $this->connectNodes($modelNode, $pivotModelNode, $relation);

            $relation = new ModelRelation(
                $relationName,
                'BelongsToMany',
                $model->getModel(),
                $eloquentRelation->getRelatedPivotKeyName(),
                $eloquentRelation->getRelated()->getKeyName()
            );

            $this->connectNodes($pivotModelNode, $relatedModelNode, $relation);
        } catch (\ReflectionException $e) {
        }
    }

    /**
     * @param Model $model
     * @param ModelRelation $relation
     * @param Node $modelNode
     * @param Node $relatedModelNode
     */
    protected function connectByRelation(
        Model $model,
        ModelRelation $relation,
        Node $modelNode,
        Node $relatedModelNode
    ): void {

        if ($relation->getType() === 'BelongsToMany') {
            $this->connectBelongsToMany($model, $relation, $modelNode, $relatedModelNode);
            return;
        }

        $this->connectNodes($modelNode, $relatedModelNode, $relation);
    }
}
