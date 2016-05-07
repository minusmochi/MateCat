<?php
/**
 * Created by PhpStorm.
 * @author domenico domenico@translated.net / ostico@gmail.com
 * Date: 02/05/16
 * Time: 20.36
 *
 */

namespace AsyncTasks\Workers;

use CatUtils,
        Contribution\ContributionStruct,
        Engine,
        Engines_MyMemory,
        TaskRunner\Commons\AbstractWorker,
        TaskRunner\Commons\QueueElement,
        TaskRunner\Exceptions\EndQueueException,
        TaskRunner\Exceptions\ReQueueException,
        TmKeyManagement_Filter,
        TmKeyManagement_TmKeyManagement,
        TaskRunner\Commons\AbstractElement;

class SetContributionWorker extends AbstractWorker {

    const ERR_SET_FAILED = 4;
    const ERR_NO_TM_ENGINE = 5;

    /**
     * @var Engines_MyMemory
     */
    protected $_tms;

    /**
     * This method is for testing purpose. Set a dependency injection
     *
     * @param \Engines_AbstractEngine $_tms
     */
    public function setEngine( $_tms ){
        $this->_tms = $_tms;
    }

    /**
     * @param AbstractElement $queueElement
     *
     * @throws EndQueueException
     * @throws ReQueueException
     *
     * @return null
     */
    public function process( AbstractElement $queueElement ) {

        /**
         * @var $queueElement QueueElement
         */
        $this->_checkForReQueueEnd( $queueElement );

        $contributionStruct = new ContributionStruct( $queueElement->params->toArray() );

        $this->_execContribution( $contributionStruct );

    }

    /**
     * Check how much times the element was re-queued and raise an Exception when the limit is reached ( 100 times )
     *
     * @param QueueElement $queueElement
     *
     * @throws EndQueueException
     */
    protected function _checkForReQueueEnd( QueueElement $queueElement ){

        /**
         *
         * check for loop re-queuing
         */
        if ( isset( $queueElement->reQueueNum ) && $queueElement->reQueueNum >= 100 ) {

            $msg = "\n\n Error Set Contribution  \n\n " . var_export( $queueElement, true );
            \Utils::sendErrMailReport( $msg );
            $this->_doLog( "--- (Worker " . $this->_workerPid . ") :  Frame Re-queue max value reached, acknowledge and skip." );
            throw new EndQueueException( "--- (Worker " . $this->_workerPid . ") :  Frame Re-queue max value reached, acknowledge and skip.", self::ERR_REQUEUE_END );

        } elseif ( isset( $queueElement->reQueueNum ) ) {
            $this->_doLog( "--- (Worker " . $this->_workerPid . ") :  Frame re-queued {$queueElement->reQueueNum} times." );
        }

    }

    /**
     * TODO implement the logic for update on mymemory
     * TODO MyMemory::update works only for the glossary now. Implement
     *
     * @param ContributionStruct $contributionStruct
     *
     * @throws EndQueueException
     * @throws ReQueueException
     * @throws \Exception
     * @throws \Exceptions\ValidationError
     */
    protected function _execContribution( ContributionStruct $contributionStruct ){

        $jobStructList = $contributionStruct->getJobStruct();
        $jobStruct = array_pop( $jobStructList );
//        $userInfoList = $contributionStruct->getUserInfo();
//        $userInfo = array_pop( $userInfoList );

        $id_tms  = $jobStruct->id_tms;
        $tm_keys = $jobStruct->tm_keys;

        if ( $id_tms != 0 ) {

            if( empty( $this->_tms ) ){
                $this->_tms = Engine::getInstance( 1 ); //Load MyMemory
            }

            $config = $this->_tms->getConfigStruct();

            $config[ 'segment' ]     = CatUtils::view2rawxliff( $contributionStruct->segment );
            $config[ 'translation' ] = CatUtils::view2rawxliff( $contributionStruct->translation );
            $config[ 'source' ]      = $jobStruct->source;
            $config[ 'target' ]      = $jobStruct->target;
            $config[ 'email' ]       = $contributionStruct->api_key;

            //get the Props
            $config[ 'prop' ]        = json_encode( $contributionStruct->getProp() );

            if ( $contributionStruct->fromRevision ) {
                $userRole = TmKeyManagement_Filter::ROLE_REVISOR;
            } else {
                $userRole = TmKeyManagement_Filter::ROLE_TRANSLATOR;
            }

            //find all the job's TMs with write grants and make a contribution to them
            $tm_keys = TmKeyManagement_TmKeyManagement::getJobTmKeys( $tm_keys, 'w', 'tm', $contributionStruct->uid, $userRole  );

            if ( !empty( $tm_keys ) ) {

                unset($config[ 'id_user' ]); //TODO: verify, exists??? Why unset???

                foreach ( $tm_keys as $i => $tm_info ) {

                    $config[ 'id_user' ] = $tm_info->key;

                    // set the contribution for every key in the job belonging to the user
                    $res = $this->_tms->set( $config );

                    if ( !$res ) {
                        throw new ReQueueException( "Set failed on " . get_class( $this->_tms ) . ": Values " . var_export( $config, true ), self::ERR_SET_FAILED );
                    }

                }

            } else {

                $res = $this->_tms->set( $config );

                if ( !$res ) {

                    if ( !$res ) {
                        throw new ReQueueException( "Set failed on " . get_class( $this->_tms ) . ": Values " . var_export( $config, true ), self::ERR_SET_FAILED );
                    }

                }

            }

        } else {
            throw new EndQueueException( "No TM engine configured for the job. Skip, OK", self::ERR_NO_TM_ENGINE );
        }

    }

}