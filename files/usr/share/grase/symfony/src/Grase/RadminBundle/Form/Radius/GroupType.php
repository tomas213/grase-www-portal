<?php

namespace Grase\RadminBundle\Form\Radius;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class GroupType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name')
            ->add('expiry')
            ->add('expireAfter')
            ->add('maxOctets', null, ['label' => 'grase.form.maxData'])
            ->add('maxSeconds')
            ->add('comment')
            ->add('save', SubmitType::class)
        ;

        $builder->get('maxOctets')
            ->addModelTransformer(new CallbackTransformer(
                // Transform Octets to Megabytes
                function ($octets) {
                    return $octets/1024/1024;
                },
                function ($megabytes) {
                    return $megabytes*1024*1024;
                }
            ));
    }
    
    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Grase\RadminBundle\Entity\Radius\Group'
        ));
    }

}
