<?php

namespace App\Controller;

use App\Controller\BaseController;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

use App\Model\Model;
use App\Model\ModelAgent;

class ModelController extends BaseController
{
    /**
     * @Route("/model", name="model.index", methods={"GET"})
     */
    public function index(Request $request, Session $session)
    {
        $all_models = $this->entityManager->getRepository(Model::class)->findAll();

        $models = array();
        foreach ($all_models as $model) {
            if (!isset($models[$model->nom()])) {
                $models[$model->nom()] = array(
                    'count' => 0,
                    'id' => $model->id(),
                    'site' => $model->site()
                );
            }

            $models[$model->nom()]['count']++;
        }

        $multi_sites = $this->config('Multisites-nombre') > 1 ? 1 : 0;
        $sites = array();
        if ($multi_sites) {
            for ($i=1; $i < $this->config('Multisites-nombre')+1; $i++) {
                $sites[$i] = $this->config("Multisites-site$i");
            }
        }

        $this->templateParams(array(
            'models' => $models,
            'multi_sites' => $multi_sites,
            'sites' => $sites,
            ));

        return $this->output('admin/model/index.html.twig');
    }

    /**
     * @Route("/model", name="model.save", methods={"POST"})
     */
    public function save(Request $request, Session $session)
    {
        $id = $request->get('id');
        $name = $request->get('name');

        $model = $this->entityManager->getRepository(Model::class)->find($id);
        $models = $this->entityManager->getRepository(Model::class)
            ->findBy(array('nom' => $model->nom()));
        $modelAgents = $this->entityManager->getRepository(ModelAgent::class)
            ->findBy(array('nom' => $model->nom()));

        foreach ($models as $model) {
            $model->nom($name);
            $this->entityManager->persist($model);
        }
        foreach ($modelAgents as $modelAgent) {
            $modelAgent->nom($name);
            $this->entityManager->persist($modelAgent);
        }

        $this->entityManager->flush();

        $session->getFlashBag()->add('notice', 'Modèle enregistré');
        return $this->redirectToRoute('model.index');
    }

    /**
     * @Route("/model/{id}", name="model.edit", methods={"GET"})
     */
    public function edit(Request $request)
    {
        $id = $request->get('id');

        $template = $this->entityManager->getRepository(Model::class)->find($id);

        $this->templateParams(array( 'template'  => $template ));

        return $this->output('admin/model/edit.html.twig');
    }


    /**
     * @Route("/model/{id}", name="model.delete", methods={"DEL"})
     */
    public function delete(Request $request, Session $session)
    {
        $id = $request->get('id');

        $model = $this->entityManager->getRepository(Model::class)->find($id);
        $models = $this->entityManager->getRepository(Model::class)
            ->findBy(array('nom' => $model->nom()));
        $modelAgents = $this->entityManager->getRepository(ModelAgent::class)
            ->findBy(array('nom' => $model->nom()));

        foreach ($models as $model) {
            $this->entityManager->remove($model);
        }
        foreach ($modelAgents as $modelAgent) {
            $this->entityManager->remove($modelAgent);
        }

        $this->entityManager->flush();

        $session->getFlashBag()->add('notice', 'Modèle supprimé');
        return $this->json(array('id' => $id));
    }
}
